<?php

namespace App\Domain\Games;

use App\Domain\Assets\Asset;
use App\Domain\Assets\AssetResolver;
use App\Supabase\Client;
use App\Supabase\Query;
use App\Supabase\SupabaseException;
use Closure;

/**
 * The encyclopedia's reads, as `lib/games/units.ts` (#227): a grid of a game's
 * units, one unit's page, and the two versions a compare view puts side by side.
 *
 * Everything here reads through row level security with an anonymous client.
 * Every read narrows to one game by filtering on `game.shortname`, and PostgREST
 * refuses such a filter unless the request also embeds `game` in its select
 * (#250). That embedded column is never read here. Dropping it brings back the
 * PGRST108 these reads used to answer.
 *
 * Maps keyed by a lower cased def are plain arrays. PHP turns a numeric string
 * key into an integer, so read them with `isset` or `[$key]`, and cast keys back
 * to strings if iterating.
 */
final class Units
{
    public const UNIT_PAGE_SIZE = 48;

    /** `Number.MAX_SAFE_INTEGER`. */
    private const MAX_SAFE_INTEGER = 9007199254740991;

    /** `fetchPage`'s code for an offset past the last matching row. */
    private const OFFSET_PAST_END = 'PGRST103';

    /**
     * The grid's own shape of the query string, parsed and bounded. Values may
     * be strings or lists of them, as a query string arrives.
     *
     * Returns `['q' => ?string, 'retired' => bool, 'faction' => ?string, 'page' => int]`.
     *
     * @param  array<string, string|list<string>|null>  $params
     * @return array{q: ?string, retired: bool, faction: ?string, page: int}
     */
    public static function parseUnitGridFilters(array $params): array
    {
        $first = function (mixed $value): ?string {
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            return $value === null ? null : (string) $value;
        };

        $q = $first($params['q'] ?? null);
        $q = $q === null ? '' : Js::trim($q);
        $faction = $first($params['faction'] ?? null);
        $faction = $faction === null ? '' : Js::trim($faction);

        $page = self::jsNumber($first($params['page'] ?? null));
        $valid = is_finite($page) && floor($page) === $page && abs($page) <= self::MAX_SAFE_INTEGER
            && $page > 1 && $page <= self::maxPage(self::UNIT_PAGE_SIZE);

        return [
            'q' => $q === '' ? null : $q,
            'retired' => $first($params['retired'] ?? null) === '1',
            'faction' => $faction === '' ? null : $faction,
            'page' => $valid ? (int) $page : 1,
        ];
    }

    /**
     * One page of a game's units. `$exclude` is the units nothing builds and no
     * start unit heads (#280), plus the morph levels that are not a base, kept
     * off the shelf.
     *
     * Returns `['units' => list of ['unit_name', 'full_name', 'faction_key'],
     * 'count' => int, 'error' => ?string]`.
     *
     * @param  array{q: ?string, retired: bool, faction: ?string, page: int}  $filters
     * @param  list<string>  $exclude
     * @return array{units: list<array<string, mixed>>, count: int, error: ?string}
     */
    public static function loadUnitGrid(Client $supabase, string $shortname, array $filters, array $exclude = []): array
    {
        // A game with no sides lists unassigned units despite exclusions.
        $excluding = $exclude !== [] && self::gameFactions($supabase, $shortname) !== [];

        $listing = function () use ($supabase, $shortname, $filters, $exclude, $excluding): Query {
            $query = $supabase->from('game_unit')
                ->select('unit_name,full_name,faction_key,game!inner(shortname)', count: 'exact')
                ->eq('game.shortname', $shortname);

            // `is`, not `eq`: PostgREST refuses `removed_at=eq.null` outright (#255).
            if (! $filters['retired']) {
                $query->is('removed_at', null);
            }
            if ($filters['faction'] !== null) {
                $query->eq('faction_key', $filters['faction']);
            }
            if ($filters['q'] !== null) {
                // Either name is a name. A visitor who typed "commander" is
                // looking for armcom, whose def key does not say Commander.
                $query->or("unit_name.ilike.%{$filters['q']}%,full_name.ilike.%{$filters['q']}%");
            }
            if ($excluding) {
                // Def keys are lowercased words, so no list here needs CSV quoting.
                $query->not('unit_name', 'in', '('.implode(',', $exclude).')');
            }

            return $query->order('unit_name')->range(
                ($filters['page'] - 1) * self::UNIT_PAGE_SIZE,
                $filters['page'] * self::UNIT_PAGE_SIZE - 1,
            );
        };

        $page = self::fetchPage($listing, fn () => $listing()->range(0, 0));
        if ($page['error'] !== null) {
            return ['units' => [], 'count' => 0, 'error' => $page['error']];
        }

        return ['units' => $page['data'], 'count' => $page['count'], 'error' => null];
    }

    /**
     * The units a player can never reach (#280): nothing builds them and no
     * start unit heads them. A mention counts only from a living row. Empty on
     * any read that fails rather than taking the grid down.
     *
     * A game where no unit reports a build option at all has no build tree to be
     * unreachable in, so there is no answer for it and the whole shelf stands.
     *
     * @return list<string>
     */
    public static function unbuildableUnits(Client $supabase, string $shortname): array
    {
        // Paged, because a truncated read would call every unit past the cap a
        // ghost and empty half the shelf.
        $rows = ReadAll::rows(fn () => $supabase->from('game_unit')
            ->select('unit_name,build_options,game!inner(shortname)')
            ->eq('game.shortname', $shortname)
            ->is('removed_at', null)
            ->order('unit_name'));

        try {
            $game = $supabase->from('game')->select('start_units')->eq('shortname', $shortname)->maybeSingle();
        } catch (SupabaseException) {
            return [];
        }
        if ($rows === null || $game === null) {
            return [];
        }

        $starts = [];
        foreach ($game['start_units'] ?? [] as $name) {
            $starts[Js::lower((string) $name)] = true;
        }
        $referenced = [];
        foreach ($rows as $row) {
            foreach ($row['build_options'] ?? [] as $option) {
                $key = is_string($option) ? Js::lower($option) : '';
                if ($key !== '') {
                    $referenced[$key] = true;
                }
            }
        }
        if ($referenced === []) {
            return [];
        }

        $ghosts = [];
        foreach ($rows as $row) {
            $key = Js::lower($row['unit_name']);
            if (! isset($referenced[$key]) && ! isset($starts[$key])) {
                $ghosts[] = $row['unit_name'];
            }
        }

        return Js::sortCodeUnits($ghosts);
    }

    /**
     * Whether this game has ever retired a unit. True on a failed read, so a
     * toggle the reader may need stays where it is.
     */
    public static function hasRetiredUnits(Client $supabase, string $shortname): bool
    {
        try {
            $held = $supabase->from('game_unit')
                ->select('unit_name,game!inner(shortname)', count: 'exact')
                ->eq('game.shortname', $shortname)
                ->not('removed_at', 'is', null)
                ->limit(1)
                ->getWithCount();
        } catch (SupabaseException) {
            return true;
        }

        return ($held['count'] ?? 0) > 0;
    }

    /**
     * The levels of a morph chain that are not its base (#295), as stored, so
     * they can ride the grid's exclusion list. Live units only: a retired level
     * neither heads a group nor drags its live levels off the shelf. Empty on a
     * failed read.
     *
     * @return list<string>
     */
    public static function morphedAwayUnits(Client $supabase, string $shortname): array
    {
        $rows = self::morphRows($supabase, $shortname, true);
        if ($rows === null) {
            return [];
        }

        $names = [];
        foreach (Morph::morphGroups($rows) as $group) {
            foreach ($group['stages'] as $stage) {
                if ($stage['name'] !== $group['base']) {
                    $names[] = $stage['unit_name'];
                }
            }
        }

        return Js::sortCodeUnits($names);
    }

    /**
     * The stages of one unit's life (#295), grouped over every unit the game
     * holds, retired ones included.
     *
     * Returns `['stages' => list, 'stage_stats' => list]`, both empty when the
     * unit turns into nothing and nothing turns into it. A stage is
     * `['unit_name', 'label', 'current', 'from', 'conditions', 'unlocks' => list
     * of ['name', 'label'], 'stats', 'found', 'removed_at']`. A stage stat row
     * is `['key', 'label', 'values' => list of string, 'changed']`.
     *
     * @return array{stages: list<array<string, mixed>>, stage_stats: list<array<string, mixed>>}
     */
    public static function loadUnitStages(Client $supabase, string $shortname, string $unitName, ?string $version = null): array
    {
        $none = ['stages' => [], 'stage_stats' => []];
        $version = self::present($version) ? $version : null;

        $rows = self::morphRows($supabase, $shortname, false, $version);
        if ($rows === null) {
            return $none;
        }

        $key = Js::lower($unitName);
        $group = null;
        foreach (Morph::morphGroups($rows) as $candidate) {
            foreach ($candidate['stages'] as $stage) {
                if ($stage['name'] === $key) {
                    $group = $candidate;
                    break 2;
                }
            }
        }
        if ($group === null) {
            return $none;
        }

        $names = array_map(fn (array $stage) => $stage['unit_name'], $group['stages']);
        try {
            $query = $supabase->from('game_unit');
            if ($version !== null) {
                $query->select(
                    'unit_name,full_name,build_options,stats,removed_at,game!inner(shortname),'
                    .'game_unit_revision(version,full_name,build_options,stats)'
                )
                    ->eq('game.shortname', $shortname)
                    ->in('unit_name', $names)
                    ->eq('game_unit_revision.version', $version);
            } else {
                $query->select('unit_name,full_name,build_options,stats,removed_at,game!inner(shortname)')
                    ->eq('game.shortname', $shortname)
                    ->in('unit_name', $names);
            }
            $held = $query->get();
        } catch (SupabaseException) {
            return $none;
        }

        $facts = [];
        foreach ($held as $row) {
            $facts[Js::lower($row['unit_name'])] = $row;
        }

        // What one stage builds, lowercased and deduplicated, from whichever
        // record the page is showing.
        $optionsOf = function (array $stage) use ($facts, $version): array {
            $row = $facts[$stage['name']] ?? null;
            $reported = $version !== null
                ? ($row['game_unit_revision'][0]['build_options'] ?? null)
                : ($row['build_options'] ?? null);
            $options = [];
            foreach ($reported ?? [] as $option) {
                $lower = is_string($option) ? Js::lower($option) : '';
                if ($lower !== '') {
                    $options[$lower] = true;
                }
            }

            return array_map('strval', array_keys($options));
        };

        // Every name any stage builds, labelled in one read. A build option
        // nobody holds keeps its own key, as the page's Builds list does.
        $all = [];
        foreach ($group['stages'] as $stage) {
            foreach ($optionsOf($stage) as $option) {
                $all[$option] = true;
            }
        }
        $unlocked = Js::sortCodeUnits(array_map('strval', array_keys($all)));
        $labels = [];
        if ($unlocked !== []) {
            try {
                $named = $supabase->from('game_unit')
                    ->select('unit_name,full_name,game!inner(shortname)')
                    ->eq('game.shortname', $shortname)
                    ->in('unit_name', $unlocked)
                    ->get();
            } catch (SupabaseException) {
                $named = [];
            }
            foreach ($named as $row) {
                $labels[Js::lower($row['unit_name'])] = $row['full_name'] ?? $row['unit_name'];
            }
        }

        $unlocksOf = function (array $stage, ?array $previous) use ($optionsOf, $labels): array {
            if ($previous === null) {
                return [];
            }
            $before = array_flip($optionsOf($previous));
            $new = array_values(array_filter($optionsOf($stage), fn (string $option) => ! isset($before[$option])));

            return array_map(
                fn (string $option) => ['name' => $option, 'label' => $labels[$option] ?? $option],
                Js::sortCodeUnits($new),
            );
        };

        $stages = [];
        foreach ($group['stages'] as $stage) {
            $row = $facts[$stage['name']] ?? null;
            $revision = $version !== null ? ($row['game_unit_revision'][0] ?? null) : null;
            $found = $version !== null ? $revision !== null : $row !== null;
            // A stage nothing leads to unlocks nothing, rather than claiming its
            // whole build list is new.
            $previous = null;
            if ($stage['from'] !== null) {
                foreach ($group['stages'] as $candidate) {
                    if ($candidate['name'] === $stage['from']) {
                        $previous = $candidate;
                        break;
                    }
                }
            }

            $stages[] = [
                'unit_name' => $stage['unit_name'],
                // A release that named nothing falls back to the def key, not
                // to today's name.
                'label' => ($version !== null ? ($revision['full_name'] ?? null) : ($row['full_name'] ?? null)) ?? $stage['unit_name'],
                'current' => $stage['name'] === $key,
                'from' => $stage['from'] !== null ? ($facts[$stage['from']]['unit_name'] ?? $stage['from']) : null,
                'conditions' => $stage['conditions'],
                'unlocks' => $unlocksOf($stage, $previous),
                'stats' => ($version !== null ? ($revision['stats'] ?? null) : ($row['stats'] ?? null)) ?? [],
                'found' => $found,
                'removed_at' => $row['removed_at'] ?? null,
            ];
        }

        // The union of every stage's stat keys, in reading order, so a stat one
        // level introduces is on the table with the lower levels reading as not
        // recorded.
        $ordered = [];
        foreach ($stages as $stage) {
            foreach (Stats::statRows($stage['stats']) as $row) {
                $ordered[$row['key']] = true;
            }
        }
        $stageStats = [];
        foreach (array_map('strval', array_keys($ordered)) as $statKey) {
            // A stat whose value is a list of records draws as its own table
            // (#261) on the stage's page, not as JSON in a narrow column here.
            foreach ($stages as $stage) {
                if (Stats::tabularStatRows($stage['stats'][$statKey] ?? null) !== null) {
                    continue 2;
                }
            }
            $values = array_map(fn (array $stage) => Stats::formatStatValue($stage['stats'][$statKey] ?? null), $stages);
            $stageStats[] = [
                'key' => $statKey,
                'label' => Stats::statLabel($statKey),
                'values' => $values,
                'changed' => count(array_unique($values)) > 1,
            ];
        }

        return ['stages' => $stages, 'stage_stats' => $stageStats];
    }

    /**
     * Every side a game has, as `['key', 'name']`, in the order the read returns
     * them. The Random side is left out. A failed read gives an empty list.
     *
     * @return list<array{key: string, name: string}>
     */
    public static function gameFactions(Client $supabase, string $shortname): array
    {
        try {
            $rows = $supabase->from('game_faction')
                ->select('key,name,game!inner(shortname)')
                ->eq('game.shortname', $shortname)
                ->get();
        } catch (SupabaseException) {
            return [];
        }

        $factions = [];
        foreach ($rows as $row) {
            if (! Factions::isRandomFaction($row)) {
                $factions[] = ['key' => $row['key'], 'name' => $row['name']];
            }
        }

        return $factions;
    }

    /**
     * Every unit of a game the catalog holds, keyed on the lower cased def, each
     * as `['name' => as stored, 'label' => full name or name]`. Keying lower
     * cased makes a blueprint's roster join case insensitive by construction.
     *
     * @return array<string, array{name: string, label: string}>
     */
    public static function unitNameLabels(Client $supabase, string $shortname): array
    {
        // Paged, because a name this misses is a blueprint entry that quietly
        // loses its link.
        $rows = ReadAll::rows(fn () => $supabase->from('game_unit')
            ->select('unit_name,full_name,game!inner(shortname)')
            ->eq('game.shortname', $shortname)
            ->order('unit_name'));

        $names = [];
        foreach ($rows ?? [] as $row) {
            $names[Js::lower($row['unit_name'])] = [
                'name' => $row['unit_name'],
                'label' => $row['full_name'] ?? $row['unit_name'],
            ];
        }

        return $names;
    }

    /**
     * What every unit of a game does, keyed on the lower cased def, for colouring
     * a blueprint's buildings. The value is `builder`, `weapon` or `energy`. A
     * unit with no role is absent.
     *
     * Reads only the stats the rule looks at and the first weapon, because every
     * unit carries a stats blob of up to 8,192 bytes and this reads them all.
     * Retired units are kept, as a layout made before a balance patch still
     * names them.
     *
     * @return array<string, string>
     */
    public static function unitRoles(Client $supabase, string $shortname): array
    {
        $rows = ReadAll::rows(fn () => $supabase->from('game_unit')
            ->select(
                'unit_name,build_options,'
                .'builder:stats->builder,weapon:stats->weapons->0,'
                .'energyMake:stats->energyMake,windGenerator:stats->windGenerator,'
                .'tidalGenerator:stats->tidalGenerator,energyUpkeep:stats->energyUpkeep,'
                .'game!inner(shortname)'
            )
            ->eq('game.shortname', $shortname)
            ->order('unit_name'));

        $roles = [];
        foreach ($rows ?? [] as $row) {
            $role = self::buildingRole($row);
            if ($role !== 'other') {
                $roles[Js::lower($row['unit_name'])] = $role;
            }
        }

        return $roles;
    }

    /**
     * Who builds this unit: the units holding it as a build option, as
     * `['name', 'label']` sorted by name.
     *
     * `$version` answers for one release, so an old page's two directions agree.
     * Without one the answer is current facts, and a retired builder does not
     * count, since the build option a retired unit holds is not a live path.
     * Empty on a failed read.
     *
     * @return list<array{name: string, label: string}>
     */
    public static function unitBuilders(Client $supabase, string $shortname, string $unitName, ?string $version = null): array
    {
        $version = self::present($version) ? $version : null;
        try {
            if ($version !== null) {
                $rows = $supabase->from('game_unit')
                    ->select(
                        'unit_name,full_name,game!inner(shortname),'
                        .'game_unit_revision!inner(full_name,build_options)'
                    )
                    ->eq('game.shortname', $shortname)
                    ->eq('game_unit_revision.version', $version)
                    ->contains('game_unit_revision.build_options', [$unitName])
                    ->get();
            } else {
                $rows = $supabase->from('game_unit')
                    ->select('unit_name,full_name,game!inner(shortname)')
                    ->eq('game.shortname', $shortname)
                    ->contains('build_options', [$unitName])
                    // `is`, not `eq`: PostgREST refuses `removed_at=eq.null` (#255).
                    ->is('removed_at', null)
                    ->get();
            }
        } catch (SupabaseException) {
            return [];
        }

        $builders = array_map(function (array $row) use ($version) {
            // A release that named nothing falls back to the def key, not to
            // today's name.
            $named = $version !== null ? ($row['game_unit_revision'][0]['full_name'] ?? null) : ($row['full_name'] ?? null);

            return ['name' => $row['unit_name'], 'label' => $named ?? $row['unit_name']];
        }, $rows);
        usort($builders, fn (array $a, array $b) => Js::localeCompare($a['name'], $b['name']));

        return $builders;
    }

    /**
     * One unit's page: current facts, or what one release said. Null when the
     * unit is not held or a read fails.
     *
     * Returns `unit_name`, `full_name`, `faction_key`, `faction_name`,
     * `build_options`, `stats`, `snippet`, `source_version`, `shown_version`
     * (set only when a revision for the asked release exists), `removed_at`,
     * `versions` (every release, newest first), `builds` and `built_by` (each a
     * list of `['name', 'label']`), `stages` and `stage_stats` (as
     * {@see loadUnitStages()}).
     *
     * @return array<string, mixed>|null
     */
    public static function loadUnitPage(Client $supabase, string $shortname, string $unitName, ?string $version = null): ?array
    {
        // The revision the reader asked for is narrowed by the server rather
        // than searched for in whatever the embed carried. It is filtered, not
        // inner joined, because a release with no revision for this unit still
        // has to render: the parent row survives with an empty embed. With no
        // version asked for there is no embed, since nothing reads it.
        $asked = self::present($version) ? $version : null;
        $columns = 'id,unit_name,full_name,faction_key,build_options,stats,snippet,source_version,removed_at,'
            .'game!inner(shortname)';
        try {
            $query = $supabase->from('game_unit');
            if ($asked !== null) {
                $query->select($columns.',game_unit_revision(version,full_name,faction_key,build_options,stats)')
                    ->eq('game_unit_revision.version', $asked);
            } else {
                $query->select($columns);
            }
            $row = $query->eq('game.shortname', $shortname)->eq('unit_name', $unitName)->maybeSingle();

            $versions = $supabase->from('game_version')
                ->select('version,last_seen_at,game!inner(shortname)')
                ->eq('game.shortname', $shortname)
                ->order('last_seen_at', ascending: false)
                ->get();
        } catch (SupabaseException) {
            return null;
        }
        if ($row === null) {
            return null;
        }

        $factions = [];
        foreach (self::gameFactions($supabase, $shortname) as $faction) {
            $factions[$faction['key']] = $faction['name'];
        }

        // An older version means the revision, when one exists. When it does not
        // the page still renders with the current facts, because a unit that
        // exists today and not in some old release is an ordinary answer.
        $revision = $asked !== null ? ($row['game_unit_revision'][0] ?? null) : null;

        $fullName = $revision !== null ? $revision['full_name'] : $row['full_name'];
        $factionKey = $revision !== null ? $revision['faction_key'] : $row['faction_key'];
        $buildOptions = $revision !== null ? $revision['build_options'] : $row['build_options'];
        $stats = $revision !== null ? $revision['stats'] : $row['stats'];

        // What it builds, labelled by whatever the catalog calls each entry. A
        // build option nobody holds is still listed under its own key.
        $options = Js::sortCodeUnits(array_values(array_unique($buildOptions ?? [])));
        $builds = [];
        if ($options !== []) {
            try {
                $named = $supabase->from('game_unit')
                    ->select('unit_name,full_name,game!inner(shortname)')
                    ->eq('game.shortname', $shortname)
                    ->in('unit_name', $options)
                    ->get();
            } catch (SupabaseException) {
                $named = [];
            }
            $labels = [];
            foreach ($named as $entry) {
                $labels[$entry['unit_name']] = $entry['full_name'] ?? $entry['unit_name'];
            }
            $builds = array_map(fn (string $name) => ['name' => $name, 'label' => $labels[$name] ?? $name], $options);
        }

        $shown = $revision !== null ? $asked : null;
        $staged = self::loadUnitStages($supabase, $shortname, $row['unit_name'], $shown);

        return [
            'unit_name' => $row['unit_name'],
            'full_name' => $fullName,
            'faction_key' => $factionKey,
            'faction_name' => $factionKey !== null && $factionKey !== '' ? ($factions[$factionKey] ?? null) : null,
            'build_options' => $buildOptions ?? [],
            'stats' => $stats ?? [],
            'snippet' => $row['snippet'],
            'source_version' => $row['source_version'],
            'shown_version' => $shown,
            'removed_at' => $row['removed_at'],
            'versions' => array_map(fn (array $entry) => $entry['version'], $versions),
            'builds' => $builds,
            'built_by' => self::unitBuilders($supabase, $shortname, $row['unit_name'], $shown),
            'stages' => $staged['stages'],
            'stage_stats' => $staged['stage_stats'],
        ];
    }

    /**
     * The buildpic for every unit in a page of the grid, keyed by unit name.
     * Each value is a resolved picture from {@see AssetResolver::resolveAsset()}.
     *
     * @param  list<array{unit_name: string}>  $units
     * @return array<string, array<string, mixed>>
     */
    public static function unitBuildpics(Client $supabase, string $shortname, array $units): array
    {
        $identities = array_map(fn (array $unit) => AssetResolver::unit($shortname, $unit['unit_name']), $units);

        $held = AssetResolver::fetchHeldAssets($supabase, $identities);
        $pictures = [];
        foreach ($identities as $index => $identity) {
            $pictures[$units[$index]['unit_name']] = AssetResolver::resolveAsset($identity, $held, null);
        }

        return $pictures;
    }

    /**
     * Every angle of a unit, resolved through the picture ladder in one read
     * rather than one per angle. Every angle comes back, held or not, so the
     * caller sees the ladder's substitution (a missing angle resolves to the
     * buildpic) and drops what it cannot draw.
     *
     * Returns a list of `['angle' => string, 'asset' => resolved picture]`.
     *
     * @return list<array{angle: string, asset: array<string, mixed>}>
     */
    public static function unitRenders(Client $supabase, string $shortname, string $unitName): array
    {
        $angles = Asset::unitRenderAngles();
        $identities = array_map(
            fn (string $angle) => AssetResolver::unit($shortname, $unitName, Asset::unitRenderVariantPrefix().$angle),
            $angles,
        );
        $held = AssetResolver::fetchHeldAssets($supabase, $identities);

        $renders = [];
        foreach ($angles as $index => $angle) {
            $renders[] = ['angle' => $angle, 'asset' => AssetResolver::resolveAsset($identities[$index], $held, null)];
        }

        return $renders;
    }

    /**
     * The buildpic on its own terms, beside the render rather than under it
     * (#259). The render's own resolution substitutes a buildpic when no top
     * down render is stored, and this read lets a page show both at once when
     * the hub holds both.
     *
     * @return array<string, mixed>
     */
    public static function unitBuildpic(Client $supabase, string $shortname, string $unitName): array
    {
        $identity = AssetResolver::unit($shortname, $unitName);
        $held = AssetResolver::fetchHeldAssets($supabase, [$identity]);

        return AssetResolver::resolveAsset($identity, $held, null);
    }

    /**
     * What two releases said about one unit, side by side. Null on a failed read
     * or when the unit is not held.
     *
     * Returns `unit_name`, `left` and `right` (each `['version', 'found',
     * 'full_name', 'faction_key', 'build_options', 'stats']`, with `found` false
     * and empty facts when the hub holds no revision for that release) and
     * `rows` (as {@see Stats::compareStatRows()}).
     *
     * @return array<string, mixed>|null
     */
    public static function loadUnitComparison(Client $supabase, string $shortname, string $unitName, string $leftVersion, string $rightVersion): ?array
    {
        try {
            $row = $supabase->from('game_unit')
                ->select(
                    'unit_name,'
                    .'game!inner(shortname),'
                    .'game_unit_revision(version,full_name,faction_key,build_options,stats)'
                )
                ->eq('game.shortname', $shortname)
                ->eq('unit_name', $unitName)
                ->order('version', ascending: false, foreignTable: 'game_unit_revision')
                ->maybeSingle();
        } catch (SupabaseException) {
            return null;
        }
        if ($row === null) {
            return null;
        }

        $side = function (string $version) use ($row): array {
            foreach ($row['game_unit_revision'] ?? [] as $revision) {
                if ($revision['version'] === $version) {
                    return [
                        'version' => $version,
                        'found' => true,
                        'full_name' => $revision['full_name'],
                        'faction_key' => $revision['faction_key'],
                        'build_options' => $revision['build_options'],
                        'stats' => $revision['stats'],
                    ];
                }
            }

            return [
                'version' => $version,
                'found' => false,
                'full_name' => null,
                'faction_key' => null,
                'build_options' => [],
                'stats' => [],
            ];
        };

        $left = $side($leftVersion);
        $right = $side($rightVersion);

        return [
            'unit_name' => $row['unit_name'],
            'left' => $left,
            'right' => $right,
            // The union of both sides' keys, so a stat one release introduced is
            // still on the table and the other side reads as not recorded.
            'rows' => Stats::compareStatRows($left['stats'] ?? [], $right['stats'] ?? []),
        ];
    }

    /**
     * Every unit of a game beside what it turns into. Grouping is the read a
     * truncated answer makes wrong rather than short, so it goes through
     * {@see ReadAll}.
     *
     * @return list<array{unit_name: string, morph_targets: mixed}>|null
     */
    private static function morphRows(Client $supabase, string $shortname, bool $liveOnly, ?string $version = null): ?array
    {
        $version = self::present($version) ? $version : null;
        $rows = ReadAll::rows(function () use ($supabase, $shortname, $liveOnly, $version) {
            // Filtered rather than inner joined, so a unit the release has no
            // record of keeps its row and reports no edges.
            $query = $supabase->from('game_unit')->select(
                $version !== null
                    ? 'unit_name,morph_targets,game!inner(shortname),game_unit_revision(version,morph_targets)'
                    : 'unit_name,morph_targets,game!inner(shortname)'
            )->eq('game.shortname', $shortname);
            // `is`, not `eq`: PostgREST refuses `removed_at=eq.null` (#255).
            if ($liveOnly) {
                $query->is('removed_at', null);
            }
            if ($version !== null) {
                $query->eq('game_unit_revision.version', $version);
            }

            return $query->order('unit_name');
        });
        if ($rows === null) {
            return null;
        }

        return array_map(fn (array $row) => [
            'unit_name' => $row['unit_name'],
            // A release that reported nothing about this unit reported no edges
            // for it. Falling back to today's would draw a chain it never had.
            'morph_targets' => $version !== null
                ? ($row['game_unit_revision'][0]['morph_targets'] ?? [])
                : ($row['morph_targets'] ?? null),
        ], $rows);
    }

    /** The widget's `M.category`, as `lib/blueprint/role.ts` reads it from the selected stats. */
    private static function buildingRole(array $row): string
    {
        $declared = fn (string $key): int|float => is_int($row[$key] ?? null) || is_float($row[$key] ?? null) ? $row[$key] : 0;

        $options = $row['build_options'] ?? [];
        if (($row['builder'] ?? null) === true || (is_array($options) && $options !== [])) {
            return 'builder';
        }
        if (($row['weapon'] ?? null) !== null) {
            return 'weapon';
        }
        if ($declared('energyMake') > 0 || $declared('windGenerator') > 0 || $declared('tidalGenerator') > 0 || $declared('energyUpkeep') < 0) {
            return 'energy';
        }

        return 'other';
    }

    /**
     * `fetchPage` from `lib/gallery/query.ts`: runs a ranged, counted query and
     * turns "the offset is past the last row" into the empty page it should
     * have been. Only then does `$count` run, to learn the real total.
     *
     * @param  Closure(): Query  $run
     * @param  Closure(): Query  $count
     * @return array{data: list<array<string, mixed>>, count: int, error: ?string}
     */
    private static function fetchPage(Closure $run, Closure $count): array
    {
        try {
            $held = $run()->getWithCount();

            return ['data' => $held['rows'], 'count' => $held['count'] ?? 0, 'error' => null];
        } catch (SupabaseException $e) {
            if ($e->errorCode !== self::OFFSET_PAST_END) {
                return ['data' => [], 'count' => 0, 'error' => $e->getMessage()];
            }
        }

        try {
            $total = $count()->getWithCount();
        } catch (SupabaseException $e) {
            return ['data' => [], 'count' => 0, 'error' => $e->getMessage()];
        }

        return ['data' => [], 'count' => $total['count'] ?? 0, 'error' => null];
    }

    /**
     * The highest page whose first row number is still a safe integer, from
     * `maxPage` in `lib/gallery/query.ts`.
     */
    private static function maxPage(int $pageSize): int
    {
        return intdiv(self::MAX_SAFE_INTEGER, $pageSize);
    }

    /** JavaScript's `Number(string)`, which is NaN for anything it cannot read. */
    private static function jsNumber(?string $value): float
    {
        if ($value === null) {
            return NAN;
        }
        $text = Js::trim($value);
        if ($text === '') {
            return 0.0;
        }
        if (preg_match('/^0[xX][0-9a-fA-F]+$/', $text)) {
            return (float) hexdec($text);
        }
        if (preg_match('/^0[bB][01]+$/', $text)) {
            return (float) bindec(substr($text, 2));
        }
        if (preg_match('/^0[oO][0-7]+$/', $text)) {
            return (float) octdec(substr($text, 2));
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $text)) {
            return (float) $text;
        }

        return NAN;
    }

    /** JavaScript truthiness for an optional string: null and empty are absent. */
    private static function present(?string $value): bool
    {
        return $value !== null && $value !== '';
    }
}

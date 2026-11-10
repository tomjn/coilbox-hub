<?php

namespace App\Domain\Games;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * The build tree (#228): a game's units grouped by faction, rooted at the start
 * units, walkable down through what each thing builds.
 *
 * The grouping rule is coilbox's own (`src/content/techForest.ts`), because the
 * question is the same question and one answer should not be two algorithms: a
 * multi-source walk seeds every root first, then assigns each other unit to the
 * first root that reaches it. Dangling build options are dropped, and units no
 * root reaches land in an explicit ungrouped block rather than being hidden.
 *
 * It is a grouping, not a hierarchy: a unit two builders make appears under its
 * faction once, but every builder that can make it lists it under itself, so
 * nothing is invisible because a spanning tree had to pick one parent.
 *
 * A morph chain is one node (#295). The levels of an upgrading commander fold
 * into their base before the walk starts. `Morph` decides the grouping and this
 * class only reads it. The fold happens after the faction scope has been
 * applied, so a chain whose levels report different sides groups within the
 * side being shown.
 *
 * A node is `['name', 'label', 'builds', 'armed', 'stages']`. A faction is
 * `['root', 'label', 'units' => [node]]`. A tree is
 * `['factions' => [faction], 'ungrouped' => [node]]`.
 */
final class Tree
{
    /**
     * Whether a unit's facts report a weapons summary (#261). An empty list is
     * not armed: it says the extraction measured none, and zero is not some.
     *
     * @param  array<string, mixed>|null  $stats
     */
    private static function armedOf(?array $stats): bool
    {
        $weapons = $stats['weapons'] ?? null;

        return is_array($weapons) && array_is_list($weapons) && count($weapons) > 0;
    }

    /**
     * Lower cased, non-empty build options. Keys are lowercased throughout,
     * matching how build options arrive, so lookups are case-insensitive by
     * construction.
     *
     * @return list<string>
     */
    private static function lowered(mixed $options): array
    {
        $out = [];
        foreach (is_array($options) ? $options : [] as $option) {
            if (is_string($option) && $option !== '') {
                $out[] = Js::lower($option);
            }
        }

        return $out;
    }

    /**
     * Group a game's units by faction.
     *
     * Each unit is `['unit_name', 'full_name', 'build_options', 'stats'?, 'morph_targets'?]`.
     *
     * @param  list<array<string, mixed>>  $units
     * @param  list<string>  $roots
     * @return array{factions: list<array<string, mixed>>, ungrouped: list<array<string, mixed>>}
     */
    public static function buildTree(array $units, array $roots): array
    {
        // The morph groups, decided before anything else reads a build option,
        // so every lookup below is already speaking in bases.
        $groups = Morph::morphGroups(array_map(
            fn (array $unit) => ['unit_name' => $unit['unit_name'], 'morph_targets' => $unit['morph_targets'] ?? null],
            $units,
        ));
        $bases = Morph::baseIndex($groups);
        $baseOf = fn (string $key): string => (string) ($bases[$key] ?? $key);

        $rowOf = [];
        foreach ($units as $unit) {
            $rowOf[Js::lower($unit['unit_name'])] = $unit;
        }

        /** One node's facts: a plain unit's own, or a whole group's folded together. */
        $known = [];

        // Groups first, so a folded entry takes its name and its label from the
        // base whatever order the rows arrived in.
        foreach ($groups as $group) {
            $options = [];
            $armed = false;
            foreach ($group['stages'] as $stage) {
                $row = $rowOf[$stage['name']] ?? null;
                foreach (self::lowered($row['build_options'] ?? []) as $key) {
                    $options[$key] = $key;
                }
                if (self::armedOf($row['stats'] ?? null)) {
                    $armed = true;
                }
            }
            $known[(string) $group['base']] = [
                'full_name' => $rowOf[$group['base']]['full_name'] ?? null,
                'options' => array_map('strval', array_values($options)),
                'armed' => $armed,
                'stages' => count($group['stages']),
            ];
        }

        foreach ($units as $unit) {
            $key = Js::lower($unit['unit_name']);
            // A level already folded into its base is not a node of its own.
            if (isset($bases[$key])) {
                continue;
            }
            $known[$key] = [
                'full_name' => $unit['full_name'] ?? null,
                'options' => self::lowered($unit['build_options'] ?? []),
                'armed' => self::armedOf($unit['stats'] ?? null),
                'stages' => 1,
            ];
        }

        $labelOf = fn (string $key): string => $known[$key]['full_name'] ?? $key;

        // What each node builds, resolved once: every option points at a base,
        // the dangling ones are dropped, and a node never lists itself. That
        // last one only arises from the fold. A plant that builds level three
        // of the commander that builds the plant would otherwise read as
        // building itself once both are folded.
        $buildsOf = [];
        foreach ($known as $key => $node) {
            $key = (string) $key;
            $options = [];
            foreach ($node['options'] as $option) {
                $options[$baseOf($option)] = true;
            }
            $builds = [];
            foreach (array_keys($options) as $option) {
                $option = (string) $option;
                if ($option !== $key && isset($known[$option])) {
                    $builds[] = $option;
                }
            }
            $buildsOf[$key] = Js::sortCodeUnits($builds);
        }

        // The roots that actually exist. A start unit the catalog has never
        // heard of would head an empty block forever, so it is dropped here
        // rather than rendered as a heading over nothing. A start unit naming
        // a level heads the group that level belongs to.
        $rootKeys = [];
        $factionOf = [];
        foreach ($roots as $root) {
            $key = is_string($root) && $root !== '' ? $baseOf(Js::lower($root)) : '';
            if ($key !== '' && isset($known[$key]) && ! isset($factionOf[$key])) {
                $factionOf[$key] = $key;
                $rootKeys[] = $key;
            }
        }

        $queue = array_map(fn (string $key) => [$key, $key], $rootKeys);
        for ($head = 0; $head < count($queue); $head++) {
            [$node, $root] = $queue[$head];
            foreach ($buildsOf[$node] ?? [] as $next) {
                if (isset($factionOf[$next])) {
                    continue;
                }
                $factionOf[$next] = $root;
                $queue[] = [$next, $root];
            }
        }

        $nodeOf = fn (string $key): array => [
            'name' => $key,
            'label' => $labelOf($key),
            'builds' => $buildsOf[$key] ?? [],
            'armed' => $known[$key]['armed'] ?? false,
            'stages' => $known[$key]['stages'] ?? 1,
        ];

        $factions = [];
        foreach ($rootKeys as $root) {
            $members = [];
            foreach ($factionOf as $key => $faction) {
                if ($faction === $root) {
                    $members[] = (string) $key;
                }
            }
            $factions[] = [
                'root' => $root,
                'label' => $labelOf($root),
                'units' => array_map($nodeOf, Js::sortCodeUnits($members)),
            ];
        }

        // A unit nothing builds and no start unit heads is a reference somebody
        // kept in the archive, not a thing a player can reach (#280). It hides
        // rather than filling the ungrouped block with ghosts. A mention from
        // another unreachable unit still counts as built by something, so an
        // orphaned cluster stays visible instead of vanishing chain by chain.
        // Resolved through the fold, so a builder that makes level three counts
        // as a mention of the commander.
        $referenced = [];
        foreach ($units as $unit) {
            foreach (self::lowered($unit['build_options'] ?? []) as $key) {
                $referenced[$baseOf($key)] = true;
            }
        }

        $ungrouped = [];
        foreach (array_keys($known) as $key) {
            $key = (string) $key;
            if (! isset($factionOf[$key]) && isset($referenced[$key])) {
                $ungrouped[] = $key;
            }
        }

        return [
            'factions' => $factions,
            'ungrouped' => array_map($nodeOf, Js::sortCodeUnits($ungrouped)),
        ];
    }

    /**
     * One game's tree: current facts, or what one release said. Null on a
     * failed read or an unknown game.
     *
     * `$factionKey` scopes the whole walk to one side's units (#258). Roots
     * come from `game.start_units`, which is not versioned: which sides a game
     * has is stable across releases in a way stats are not, and a root nobody
     * reports is dropped by the walk.
     *
     * @return array{factions: list<array<string, mixed>>, ungrouped: list<array<string, mixed>>}|null
     */
    public static function loadTree(Client $supabase, string $shortname, ?string $version = null, ?string $factionKey = null): ?array
    {
        $versioned = $version !== null && $version !== '';

        // Paged, because the walk is over a whole game and a truncated read
        // does not draw a smaller tree, it draws a wrong one: every build
        // option pointing at a unit past the cap is dropped as dangling.
        $units = ReadAll::rows(function () use ($supabase, $shortname, $version, $versioned) {
            if ($versioned) {
                return $supabase
                    ->from('game_unit')
                    ->select(
                        'unit_name,full_name,faction_key,stats,build_options,morph_targets,game!inner(shortname),'.
                        'game_unit_revision!inner(version,full_name,faction_key,build_options,morph_targets,stats)',
                    )
                    ->eq('game.shortname', $shortname)
                    ->eq('game_unit_revision.version', $version)
                    ->order('unit_name');
            }

            return $supabase
                ->from('game_unit')
                ->select('unit_name,full_name,faction_key,build_options,morph_targets,stats,game!inner(shortname)')
                ->eq('game.shortname', $shortname)
                // `is`, not `eq`: PostgREST refuses `removed_at=eq.null`, and a
                // refused read here is a null tree, which the page shows as a
                // 404 (#255).
                ->is('removed_at', null)
                ->order('unit_name');
        });

        try {
            $game = $supabase->from('game')->select('start_units')->eq('shortname', $shortname)->maybeSingle();
        } catch (SupabaseException) {
            return null;
        }
        if ($units === null || $game === null) {
            return null;
        }

        // A versioned read rides the inner join to the revision and reads from
        // there. A current read has the columns on the row itself.
        $rows = [];
        foreach ($units as $row) {
            if ($versioned) {
                $revision = $row['game_unit_revision'][0] ?? null;
                $row = [
                    'unit_name' => $row['unit_name'],
                    'full_name' => $revision['full_name'] ?? $row['full_name'] ?? null,
                    'build_options' => $revision['build_options'] ?? [],
                    'faction_key' => $revision['faction_key'] ?? $row['faction_key'] ?? null,
                    'stats' => $revision['stats'] ?? $row['stats'] ?? null,
                    // The release's own grouping, so an old page's chain is the
                    // chain that release reported rather than today's.
                    'morph_targets' => $revision['morph_targets'] ?? $row['morph_targets'] ?? null,
                ];
            }
            $rows[] = [
                'unit_name' => $row['unit_name'],
                'full_name' => $row['full_name'] ?? null,
                'build_options' => $row['build_options'] ?? [],
                'faction_key' => $row['faction_key'] ?? null,
                'stats' => $row['stats'] ?? null,
                'morph_targets' => $row['morph_targets'] ?? [],
            ];
        }

        $scoped = $factionKey !== null && $factionKey !== ''
            ? array_values(array_filter($rows, fn (array $row) => $row['faction_key'] === $factionKey))
            : $rows;

        return self::buildTree($scoped, $game['start_units'] ?? []);
    }
}

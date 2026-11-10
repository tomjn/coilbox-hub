<?php

namespace App\Domain\Games;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * What changed between two releases of a game.
 *
 * ## What a release holds
 *
 * `submit_game_facts` writes one `game_unit_revision` for every unit a
 * submission names, whether or not its facts moved since the last release. So a
 * complete report leaves a revision for every unit the release shipped, and a
 * unit with no revision at a release was not in that release's report. It is
 * not "unchanged since an earlier one".
 *
 * That makes the state of a unit at a release the revision keyed by exactly
 * that release. Added is a revision in the newer release and none in the
 * older. Removed is the reverse. `game_unit.removed_at` is not consulted: it is
 * one timestamp for today's state and says nothing about any release.
 *
 * The weak spot is a partial submission, which names only some units and
 * retires none. A release reported that way holds revisions for only those
 * units, and the rest would read as missing from it.
 *
 * `game_version.reported_in_full` closes that. It is true once a complete
 * submission stored every unit it named, and false for every other release,
 * including all that were reported before the column existed. Removed units
 * need the newer release to be complete, added units need the older one to be.
 * Changed and unchanged units need only that both releases hold a revision.
 *
 * Shapes, all arrays:
 * - release unit: `unit_name, full_name, faction_key, build_options, stats`
 * - unit ref: `unit_name, full_name, faction_key`
 * - stat change: `key, label, from, to`, both sides as the compare page prints
 *   them
 * - unit change: a unit ref plus `renamed` and `faction` (each null or
 *   `['from', 'to']`), `stats` (stat changes) and `builds` (`gained`, `lost`)
 * - release changes: `added, removed, addedKnown, removedKnown, changed, unchanged`
 */
final class Changes
{
    /**
     * Lower cased, deduplicated and sorted. The array order of a unit's build
     * options is not a fact, and the tree matches them case insensitively.
     *
     * @return list<string>
     */
    private static function optionSet(mixed $options): array
    {
        $set = [];
        foreach (is_array($options) ? $options : [] as $option) {
            if (is_string($option) && $option !== '') {
                $set[Js::lower($option)] = true;
            }
        }

        return Js::sortCodeUnits(array_map('strval', array_keys($set)));
    }

    /** @param array<string, mixed> $unit @return array<string, mixed> */
    private static function ref(array $unit): array
    {
        return [
            'unit_name' => $unit['unit_name'],
            'full_name' => $unit['full_name'],
            'faction_key' => $unit['faction_key'],
        ];
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array<string, mixed>|null
     */
    private static function diffUnit(array $from, array $to): ?array
    {
        $fromStats = $from['stats'] ?? [];
        $toStats = $to['stats'] ?? [];

        $keys = [];
        foreach ([...Stats::statRows($fromStats), ...Stats::statRows($toStats)] as $row) {
            $keys[(string) $row['key']] = true;
        }
        $stats = [];
        foreach (array_keys($keys) as $key) {
            $key = (string) $key;
            $before = $fromStats[$key] ?? null;
            $after = $toStats[$key] ?? null;
            if (! Stats::statValueChanged($before, $after)) {
                continue;
            }
            $stats[] = [
                'key' => $key,
                'label' => Stats::statLabel($key),
                'from' => Stats::formatStatValue($before),
                'to' => Stats::formatStatValue($after),
            ];
        }

        $before = self::optionSet($from['build_options'] ?? []);
        $after = self::optionSet($to['build_options'] ?? []);
        $builds = [
            'gained' => array_values(array_diff($after, $before)),
            'lost' => array_values(array_diff($before, $after)),
        ];

        $renamed = $from['full_name'] !== $to['full_name']
            ? ['from' => $from['full_name'], 'to' => $to['full_name']]
            : null;
        $faction = $from['faction_key'] !== $to['faction_key']
            ? ['from' => $from['faction_key'], 'to' => $to['faction_key']]
            : null;

        if ($stats === [] && $builds['gained'] === [] && $builds['lost'] === [] && $renamed === null && $faction === null) {
            return null;
        }

        return [...self::ref($to), 'renamed' => $renamed, 'faction' => $faction, 'stats' => $stats, 'builds' => $builds];
    }

    /**
     * The difference between two releases, each as the units its revisions
     * hold. Lists come back unsorted. Callers order them for display.
     *
     * @param  list<array<string, mixed>>  $from
     * @param  list<array<string, mixed>>  $to
     * @param  array{from: bool, to: bool}  $complete  whether the hub knows each release was reported in full
     * @return array<string, mixed>
     */
    public static function diffReleases(array $from, array $to, array $complete): array
    {
        $older = [];
        foreach ($from as $unit) {
            $older[$unit['unit_name']] = $unit;
        }
        $newer = [];
        foreach ($to as $unit) {
            $newer[$unit['unit_name']] = $unit;
        }

        $added = [];
        $changed = [];
        $unchanged = 0;
        foreach ($newer as $name => $unit) {
            $before = $older[$name] ?? null;
            if ($before === null) {
                $added[] = self::ref($unit);

                continue;
            }
            $change = self::diffUnit($before, $unit);
            if ($change !== null) {
                $changed[] = $change;
            } else {
                $unchanged++;
            }
        }

        $removed = [];
        foreach ($older as $name => $unit) {
            if (! array_key_exists($name, $newer)) {
                $removed[] = self::ref($unit);
            }
        }

        return [
            'added' => $complete['from'] ? $added : [],
            'removed' => $complete['to'] ? $removed : [],
            'addedKnown' => $complete['from'],
            'removedKnown' => $complete['to'],
            'changed' => $changed,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * Units grouped by faction, in the order the game lists its sides, with a
     * last group for units whose faction is empty or no longer listed. Inside a
     * group units read by display name, falling back to the def name.
     *
     * Each group is `['key', 'name', 'units']`.
     *
     * @param  list<array<string, mixed>>  $units
     * @param  list<array{key: string, name: string}>  $factions
     * @return list<array<string, mixed>>
     */
    public static function groupByFaction(array $units, array $factions): array
    {
        $label = fn (array $unit): string => $unit['full_name'] ?? $unit['unit_name'];
        $sorted = $units;
        usort($sorted, fn (array $a, array $b) => Js::localeCompare($label($a), $label($b))
            ?: Js::localeCompare($a['unit_name'], $b['unit_name']));

        $groups = [];
        foreach ($factions as $faction) {
            $groups[] = [
                'key' => $faction['key'],
                'name' => $faction['name'],
                'units' => array_values(array_filter($sorted, fn (array $unit) => ($unit['faction_key'] ?? null) === $faction['key'])),
            ];
        }
        $known = array_map(fn (array $faction) => $faction['key'], $factions);
        $groups[] = [
            'key' => null,
            'name' => 'No faction',
            'units' => array_values(array_filter(
                $sorted,
                fn (array $unit) => ($unit['faction_key'] ?? '') === '' || ! in_array($unit['faction_key'], $known, true),
            )),
        ];

        return array_values(array_filter($groups, fn (array $group) => $group['units'] !== []));
    }

    /**
     * The pair of releases the page shows.
     *
     * `$releases` is newest first. A name the hub does not hold falls back to
     * the default for its side and earns a note. A pair the reader chose is
     * kept as chosen even when `from` is the newer. The sections are named for
     * the two releases they compare, and which is newer is not a fact the hub
     * records reliably, since a release's `last_seen_at` moves on every report.
     *
     * @param  list<string>  $releases
     * @return array{from: string|null, to: string|null, notes: list<string>}
     */
    public static function resolveReleasePair(array $releases, ?string $askedFrom, ?string $askedTo): array
    {
        if (count($releases) < 2) {
            return ['from' => null, 'to' => null, 'notes' => []];
        }

        $notes = [];
        $known = function (?string $asked) use ($releases, &$notes): ?string {
            if ($asked === null || $asked === '') {
                return null;
            }
            if (in_array($asked, $releases, true)) {
                return $asked;
            }
            $notes[] = 'The hub holds no release called "'.$asked.'".';

            return null;
        };
        $from = $known($askedFrom);
        $to = $known($askedTo);

        // A side left to default takes the neighbour of the other side, so one
        // good name beside a bad one does not collapse into the same release
        // twice.
        $beside = function (string $release, int $step) use ($releases): ?string {
            $at = array_search($release, $releases, true);

            return $releases[$at + $step] ?? $releases[$at - $step] ?? null;
        };

        return [
            'from' => $from ?? ($to !== null ? $beside($to, 1) : $releases[1]),
            'to' => $to ?? ($from !== null ? $beside($from, -1) : $releases[0]),
            'notes' => $notes,
        ];
    }

    /**
     * Every release a game has, newest report first, as the unit page's picker
     * orders them. Null on a failed read.
     *
     * @return list<array{version: string, last_seen_at: string}>|null
     */
    public static function loadGameReleases(Client $supabase, string $shortname): ?array
    {
        try {
            $rows = $supabase
                ->from('game_version')
                ->select('version,last_seen_at,game!inner(shortname)')
                ->eq('game.shortname', $shortname)
                ->order('last_seen_at', ascending: false)
                ->get();
        } catch (SupabaseException) {
            return null;
        }

        return array_map(fn (array $row) => [
            'version' => $row['version'],
            'last_seen_at' => $row['last_seen_at'],
        ], $rows);
    }

    /**
     * The difference between two releases of one game. Null on a failed read.
     *
     * Both releases' revisions come in one paged read, filtered to the game and
     * the two versions, and are split by version here. A game has hundreds of
     * units, so a read per unit is not an option, and `ReadAll` keeps a game
     * past the response cap from being read short.
     *
     * @return array<string, mixed>|null
     */
    public static function loadReleaseChanges(Client $supabase, string $shortname, string $from, string $to): ?array
    {
        $versions = array_values(array_unique([$from, $to]));
        $rows = ReadAll::rows(fn () => $supabase
            ->from('game_unit_revision')
            ->select('id,version,full_name,faction_key,build_options,stats,game_unit!inner(unit_name,game!inner(shortname))')
            ->eq('game_unit.game.shortname', $shortname)
            ->in('version', $versions)
            ->order('id'));
        if ($rows === null) {
            return null;
        }

        $side = function (string $version) use ($rows): array {
            $units = [];
            foreach ($rows as $row) {
                if ($row['version'] !== $version) {
                    continue;
                }
                $units[] = [
                    'unit_name' => $row['game_unit']['unit_name'],
                    'full_name' => $row['full_name'],
                    'faction_key' => $row['faction_key'],
                    'build_options' => $row['build_options'] ?? [],
                    'stats' => $row['stats'] ?? [],
                ];
            }

            return $units;
        };

        try {
            $held = $supabase
                ->from('game_version')
                ->select('version,reported_in_full,game!inner(shortname)')
                ->eq('game.shortname', $shortname)
                ->in('version', $versions)
                ->get();
        } catch (SupabaseException) {
            return null;
        }
        $reported = function (string $version) use ($held): bool {
            foreach ($held as $row) {
                if ($row['version'] === $version && ($row['reported_in_full'] ?? null) === true) {
                    return true;
                }
            }

            return false;
        };

        return self::diffReleases($side($from), $side($to), ['from' => $reported($from), 'to' => $reported($to)]);
    }
}

<?php

namespace App\Domain\Games;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * Two different units of one game, side by side, as `lib/games/compareUnits.ts`
 * (#466).
 *
 * The page reads both at one release. A unit's state at a release is the
 * revision keyed by exactly that release, so a release can hold one of the two
 * and not the other.
 */
final class CompareUnits
{
    /**
     * What the `with` parameter names. The form submits an id, but a visitor can
     * type a display name, so an id is tried first and a display name second,
     * both ignoring case. A display name several units share lists them rather
     * than picking one. The unit being compared is left out of a shared name's
     * matches, and is `self` only when nothing else matches.
     *
     * Returns one of:
     * - `['kind' => 'none']`
     * - `['kind' => 'self']`
     * - `['kind' => 'unknown', 'asked' => string]`
     * - `['kind' => 'ambiguous', 'choices' => list of unit labels]`
     * - `['kind' => 'found', 'unit' => ['name' => string, 'label' => string]]`
     *
     * @param  list<array{name: string, label: string}>  $units
     * @return array<string, mixed>
     */
    public static function resolveWithUnit(?string $asked, string $self, array $units): array
    {
        $text = $asked === null ? '' : Js::lower(Js::trim($asked));
        if ($text === '') {
            return ['kind' => 'none'];
        }

        foreach ($units as $unit) {
            if (Js::lower($unit['name']) === $text) {
                return $unit['name'] === $self ? ['kind' => 'self'] : ['kind' => 'found', 'unit' => $unit];
            }
        }

        $byLabel = array_values(array_filter($units, fn (array $unit) => Js::lower($unit['label']) === $text));
        if ($byLabel === []) {
            return ['kind' => 'unknown', 'asked' => Js::trim((string) $asked)];
        }
        $others = array_values(array_filter($byLabel, fn (array $unit) => $unit['name'] !== $self));
        if ($others === []) {
            return ['kind' => 'self'];
        }
        if (count($others) === 1) {
            return ['kind' => 'found', 'unit' => $others[0]];
        }

        return ['kind' => 'ambiguous', 'choices' => $others];
    }

    /**
     * The release to compare at. `$releases` is every release the game holds,
     * newest first, and `$heldA` and `$heldB` are the releases each unit has a
     * revision for. With no release asked for the newest holding both is used.
     * A release asked for is used only when both hold it.
     *
     * Returns `release` (?string), `both` (list of releases holding both units,
     * newest first), `missing` (null, or `['a' => bool, 'b' => bool]` when a
     * release was asked for and one unit lacks it) and `unknownRelease` (true
     * when the release asked for is not one the game holds).
     *
     * @param  list<string>  $releases
     * @param  list<string>  $heldA
     * @param  list<string>  $heldB
     * @return array{release: ?string, both: list<string>, missing: ?array{a: bool, b: bool}, unknownRelease: bool}
     */
    public static function pickCompareRelease(array $releases, array $heldA, array $heldB, ?string $asked): array
    {
        $a = array_flip($heldA);
        $b = array_flip($heldB);
        $both = array_values(array_filter($releases, fn (string $release) => isset($a[$release], $b[$release])));

        if ($asked === null || $asked === '') {
            return ['release' => $both[0] ?? null, 'both' => $both, 'missing' => null, 'unknownRelease' => false];
        }
        if (! in_array($asked, $releases, true)) {
            return ['release' => null, 'both' => $both, 'missing' => null, 'unknownRelease' => true];
        }
        if (isset($a[$asked], $b[$asked])) {
            return ['release' => $asked, 'both' => $both, 'missing' => null, 'unknownRelease' => false];
        }

        return [
            'release' => null,
            'both' => $both,
            'missing' => ['a' => ! isset($a[$asked]), 'b' => ! isset($b[$asked])],
            'unknownRelease' => false,
        ];
    }

    /**
     * The two units with all their revisions, in the order asked. Null on a
     * failed read or when either is not held by the game. Two units' revisions
     * are a handful of rows, so one embedded read is enough.
     *
     * Each unit is `['unit_name', 'full_name', 'revisions' => list of
     * ['version', 'full_name', 'faction_key', 'stats']]`.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    public static function loadUnitPair(Client $supabase, string $shortname, string $nameA, string $nameB): ?array
    {
        try {
            $rows = $supabase->from('game_unit')
                ->select(
                    'unit_name,full_name,game!inner(shortname),'
                    .'game_unit_revision(version,full_name,faction_key,stats)'
                )
                ->eq('game.shortname', $shortname)
                ->in('unit_name', [$nameA, $nameB])
                ->get();
        } catch (SupabaseException) {
            return null;
        }

        $unit = function (string $name) use ($rows): ?array {
            foreach ($rows as $row) {
                if ($row['unit_name'] === $name) {
                    return [
                        'unit_name' => $row['unit_name'],
                        'full_name' => $row['full_name'],
                        'revisions' => $row['game_unit_revision'] ?? [],
                    ];
                }
            }

            return null;
        };
        $a = $unit($nameA);
        $b = $unit($nameB);

        return $a !== null && $b !== null ? [$a, $b] : null;
    }
}

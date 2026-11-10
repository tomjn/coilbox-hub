<?php

namespace App\Domain\Games;

/**
 * What a unit turns into, grouped, as `lib/games/morph.ts` (#295).
 *
 * A commander that upgrades through tech levels is one unit at five stages of
 * its life, and the catalog holds five unrelated rows. This turns the morph
 * edges those rows carry into groups, so the grid can show one cell, the build
 * tree one node, and a unit's page the levels together.
 *
 * Keys are lowercased throughout, matching how morph targets and build options
 * arrive. The graph is not a chain: edges branch, converge, and a game can
 * define a cycle.
 *
 * - A group is a connected component, direction ignored.
 * - The base is a member nothing morphs into. Several, or none because the
 *   component is a cycle, and the first by code unit wins.
 * - Stages run breadth first from the base, siblings alphabetical, and any
 *   member the base cannot reach is appended.
 *
 * Shapes:
 * - unit: `['unit_name' => string, 'morph_targets' => mixed]`
 * - edge: `['into' => string, 'conditions' => array]`
 * - stage: `['name', 'unit_name', 'from' (?string), 'conditions', 'into' (edges)]`
 * - group: `['base' => string, 'stages' => list of stages]`
 */
final class Morph
{
    /**
     * @param  list<array{unit_name: string, morph_targets: mixed}>  $units
     * @return list<array{base: string, stages: list<array<string, mixed>>}>
     */
    public static function morphGroups(array $units): array
    {
        $held = [];
        $stored = [];
        foreach ($units as $unit) {
            $key = Js::lower($unit['unit_name']);
            $held[$key] = true;
            $stored[$key] = $unit['unit_name'];
        }

        $out = [];
        $neighbours = [];
        $incoming = [];
        foreach ($units as $unit) {
            $key = Js::lower($unit['unit_name']);
            $edges = self::edgesOf($unit, $held);
            if ($edges === []) {
                continue;
            }
            $out[$key] = $edges;
            foreach ($edges as $edge) {
                $neighbours[$key][$edge['into']] = true;
                $neighbours[$edge['into']][$key] = true;
                $incoming[$edge['into']] = ($incoming[$edge['into']] ?? 0) + 1;
            }
        }

        // The components, direction ignored. A unit with no edge either way is
        // not in `$neighbours` at all, so a group of one never arises.
        $claimed = [];
        $groups = [];
        foreach (self::sortedKeys($neighbours) as $start) {
            if (isset($claimed[$start])) {
                continue;
            }

            $members = [];
            $queue = [$start];
            $claimed[$start] = true;
            while ($queue !== []) {
                $key = array_shift($queue);
                $members[] = $key;
                foreach (self::sortedKeys($neighbours[$key] ?? []) as $next) {
                    if (isset($claimed[$next])) {
                        continue;
                    }
                    $claimed[$next] = true;
                    $queue[] = $next;
                }
            }

            $groups[] = self::groupOf($members, $out, $incoming, $stored);
        }

        usort($groups, fn (array $a, array $b) => Js::compareCodeUnits($a['base'], $b['base']));

        return $groups;
    }

    /**
     * Every grouped unit's base, keyed on the lower cased def. A unit in no
     * group is absent rather than mapped to itself.
     *
     * @param  list<array{base: string, stages: list<array<string, mixed>>}>  $groups
     * @return array<string, string>
     */
    public static function baseIndex(array $groups): array
    {
        $bases = [];
        foreach ($groups as $group) {
            foreach ($group['stages'] as $stage) {
                $bases[$stage['name']] = $group['base'];
            }
        }

        return $bases;
    }

    /**
     * The edges one unit declares, keyed and cleaned. Anything that is not an
     * object naming a unit as a string is dropped, so a row written before the
     * parser knew the field cannot take down the page.
     *
     * @param  array<string, true>  $held
     * @return list<array{into: string, conditions: array<string, mixed>}>
     */
    private static function edgesOf(array $unit, array $held): array
    {
        $targets = $unit['morph_targets'] ?? null;
        if (! is_array($targets) || ! array_is_list($targets)) {
            return [];
        }

        $self = Js::lower($unit['unit_name']);
        $seen = [];
        $edges = [];
        foreach ($targets as $entry) {
            // An empty array is an empty object here, which names no unit.
            if (! is_array($entry) || (array_is_list($entry) && $entry !== []) || ! is_string($entry['into'] ?? null)) {
                continue;
            }
            $into = Js::lower(Js::trim($entry['into']));
            // A unit nobody holds is a typo in one extraction, and a unit that
            // morphs into itself has nothing to say. Neither groups anything.
            if ($into === '' || $into === $self || ! isset($held[$into]) || isset($seen[$into])) {
                continue;
            }
            $seen[$into] = true;
            $edges[] = [
                'into' => $into,
                'conditions' => array_filter($entry, fn ($value, $field) => $field !== 'into', ARRAY_FILTER_USE_BOTH),
            ];
        }
        usort($edges, fn (array $a, array $b) => Js::compareCodeUnits($a['into'], $b['into']));

        return $edges;
    }

    /**
     * One component, based and ordered.
     *
     * @param  list<string>  $members
     * @param  array<string, list<array<string, mixed>>>  $out
     * @param  array<string, int>  $incoming
     * @param  array<string, string>  $stored
     * @return array{base: string, stages: list<array<string, mixed>>}
     */
    private static function groupOf(array $members, array $out, array $incoming, array $stored): array
    {
        $sorted = Js::sortCodeUnits($members);
        // The unit nothing morphs into. A cycle has none, and then the first
        // member by code unit heads it, so readers need no special case.
        $base = $sorted[0];
        foreach ($sorted as $key) {
            if (($incoming[$key] ?? 0) === 0) {
                $base = $key;
                break;
            }
        }

        // Breadth first from the base, so a stage always follows the stage it
        // is reached from.
        $from = [];
        $order = [];
        $walked = [$base => true];
        $queue = [$base];
        while ($queue !== []) {
            $key = array_shift($queue);
            $order[] = $key;
            foreach ($out[$key] ?? [] as $edge) {
                if (isset($walked[$edge['into']])) {
                    continue;
                }
                $walked[$edge['into']] = true;
                $from[$edge['into']] = ['from' => $key, 'conditions' => $edge['conditions']];
                $queue[] = $edge['into'];
            }
        }

        // Anything the base cannot reach is appended and reached from nothing,
        // rather than given a predecessor it never had.
        foreach ($sorted as $key) {
            if (! isset($walked[$key])) {
                $order[] = $key;
            }
        }

        return [
            'base' => $base,
            'stages' => array_map(fn (string $key) => [
                'name' => $key,
                'unit_name' => $stored[$key] ?? $key,
                'from' => $from[$key]['from'] ?? null,
                'conditions' => $from[$key]['conditions'] ?? [],
                'into' => $out[$key] ?? [],
            ], $order),
        ];
    }

    /**
     * PHP turns numeric string keys into integers, so keys are cast back.
     *
     * @param  array<array-key, mixed>  $set
     * @return list<string>
     */
    private static function sortedKeys(array $set): array
    {
        return Js::sortCodeUnits(array_map('strval', array_keys($set)));
    }
}

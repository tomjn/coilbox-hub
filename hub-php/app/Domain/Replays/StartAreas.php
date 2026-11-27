<?php

namespace App\Domain\Replays;

/**
 * Starts from many matches counted by area, as Coilbox's map page counts them
 * (`placeRecords` in `src/content/mapRecords.ts`). A start near a position the
 * map declares is at that position. The rest are grouped by distance, which
 * works with start boxes and without. A figure is always "won 3 of 5", never a
 * percentage, so the size of the sample is beside it and none is too small to
 * show.
 */
final class StartAreas
{
    /**
     * The areas, most taken first. `number` is a declared position's place in
     * the map's own list from 1, and groups are numbered after them. `known`
     * counts the starts in matches somebody won.
     *
     * @param  list<array{game_id: string, x: float, z: float, won: ?bool}>  $starts
     * @param  list<array{x: int|float, z: int|float}>  $declared
     * @return list<array{number: int, declared: bool, x: float, z: float, taken: int, known: int, won: int, replays: int}>
     */
    public static function of(array $starts, array $declared, int|float $width, int|float $height): array
    {
        $tolerance = self::tolerance($declared);
        $onDeclared = [];
        $strays = [];
        foreach ($starts as $start) {
            $at = self::nearest($start, $declared, $tolerance);
            if ($at === null) {
                $strays[] = $start;
            } else {
                $onDeclared[$at][] = $start;
            }
        }

        $areas = [];
        foreach ($onDeclared as $index => $members) {
            $areas[] = self::area($index + 1, true, (float) $declared[$index]['x'], (float) $declared[$index]['z'], $members);
        }

        $groups = [];
        foreach (self::cluster($strays, min($width, $height) * HeatPicture::RADIUS_FRACTION) as $members) {
            $x = array_sum(array_column($members, 'x')) / count($members);
            $z = array_sum(array_column($members, 'z')) / count($members);
            $groups[] = self::area(0, false, $x, $z, $members);
        }
        usort($groups, fn ($a, $b) => [$b['taken'], $a['x'], $a['z']] <=> [$a['taken'], $b['x'], $b['z']]);
        foreach ($groups as $i => $group) {
            $group['number'] = count($declared) + $i + 1;
            $areas[] = $group;
        }
        usort($areas, fn ($a, $b) => [$b['taken'], $a['number']] <=> [$a['taken'], $b['number']]);

        return $areas;
    }

    /**
     * `declaredTolerance`: half the smallest distance between two declared
     * positions, the largest reach at which no start is near two of them. Null
     * with fewer than two distinct positions.
     *
     * @param  list<array{x: int|float, z: int|float}>  $declared
     */
    private static function tolerance(array $declared): ?float
    {
        $smallest = INF;
        foreach ($declared as $i => $a) {
            foreach (array_slice($declared, $i + 1) as $b) {
                $d = hypot($a['x'] - $b['x'], $a['z'] - $b['z']);
                if ($d > 0 && $d < $smallest) {
                    $smallest = $d;
                }
            }
        }

        return is_finite($smallest) ? $smallest / 2 : null;
    }

    /** `nearestDeclared`: the position a start is strictly within reach of. */
    private static function nearest(array $start, array $declared, ?float $tolerance): ?int
    {
        if ($tolerance === null) {
            return null;
        }
        $best = null;
        $bestDistance = INF;
        foreach ($declared as $i => $d) {
            $distance = hypot($start['x'] - $d['x'], $start['z'] - $d['z']);
            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$i, $distance];
            }
        }

        return $bestDistance < $tolerance ? $best : null;
    }

    /**
     * `clusterStarts`: any two starts within `$reach` of each other, directly
     * or through others, are in one group. The same starts in any order give
     * the same groups.
     *
     * @param  list<array<string, mixed>>  $points
     * @return list<list<array<string, mixed>>>
     */
    private static function cluster(array $points, float $reach): array
    {
        usort($points, fn ($a, $b) => [$a['x'], $a['z']] <=> [$b['x'], $b['z']]);
        $parent = array_keys($points);
        $find = function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }

            return $i;
        };
        $count = count($points);
        for ($a = 0; $a < $count; $a++) {
            for ($b = $a + 1; $b < $count && $points[$b]['x'] - $points[$a]['x'] <= $reach; $b++) {
                if (hypot($points[$b]['x'] - $points[$a]['x'], $points[$b]['z'] - $points[$a]['z']) <= $reach) {
                    [$ra, $rb] = [$find($a), $find($b)];
                    if ($ra !== $rb) {
                        $parent[max($ra, $rb)] = min($ra, $rb);
                    }
                }
            }
        }
        $groups = [];
        foreach ($points as $i => $point) {
            $groups[$find($i)][] = $point;
        }

        return array_values($groups);
    }

    /** @param list<array{game_id: string, x: float, z: float, won: ?bool}> $members */
    private static function area(int $number, bool $declared, float $x, float $z, array $members): array
    {
        return [
            'number' => $number, 'declared' => $declared, 'x' => $x, 'z' => $z,
            'taken' => count($members),
            'known' => count(array_filter($members, fn ($m) => $m['won'] !== null)),
            'won' => count(array_filter($members, fn ($m) => $m['won'] === true)),
            'replays' => count(array_unique(array_column($members, 'game_id'))),
        ];
    }
}

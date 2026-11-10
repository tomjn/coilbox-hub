<?php

namespace App\Domain\Maps;

use stdClass;

/**
 * One map's facts as the hub holds them, and the digest that says two
 * submissions are the same facts. `lib/maps/facts.ts`.
 *
 * An entry is an array with the keys of `public.map` a client can measure, in
 * the wire's snake case. `appearance` and each point's `meta` are `stdClass`,
 * because they pass through as the archive declared them and an empty one is
 * still an object. Every optional field is present and null rather than absent,
 * so an omitted field and an explicit null reach one digest.
 *
 * The digest must equal the one the Next app computed for the same facts, so
 * {@see canonicalJson()} writes strings and numbers exactly as `JSON.stringify` does.
 */
final class Facts
{
    /**
     * The entry in the one order the hub stores and hashes it in. Metal spots
     * and geo vents are sorted, because their order in the archive is arbitrary.
     * Start positions are not, because a start position's order is its team.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function canonicalEntry(array $entry): array
    {
        $points = $entry['points'];
        foreach (['metal', 'geo'] as $kind) {
            $sorted = $points[$kind];
            usort($sorted, self::comparePoints(...));
            $points[$kind] = $sorted;
        }
        $entry['points'] = ['start' => $points['start'], 'metal' => $points['metal'], 'geo' => $points['geo']];

        return $entry;
    }

    /**
     * Position first, then the rest of the point, so two spots at one coordinate
     * never swap places between runs.
     *
     * @param  array{x: int|float, z: int|float, y: int|float|null, meta: stdClass|null}  $left
     * @param  array{x: int|float, z: int|float, y: int|float|null, meta: stdClass|null}  $right
     */
    private static function comparePoints(array $left, array $right): int
    {
        if ($left['x'] != $right['x']) {
            return $left['x'] < $right['x'] ? -1 : 1;
        }
        if ($left['z'] != $right['z']) {
            return $left['z'] < $right['z'] ? -1 : 1;
        }
        $leftRest = self::canonicalJson(['y' => $left['y'], 'meta' => $left['meta']]);
        $rightRest = self::canonicalJson(['y' => $right['y'], 'meta' => $right['meta']]);
        if ($leftRest === $rightRest) {
            return 0;
        }

        return Js::compare($leftRest, $rightRest) < 0 ? -1 : 1;
    }

    /**
     * A value as one settled string, whatever order its keys arrived in. Keys
     * sort by UTF-16 code unit, as `Array.prototype.sort()` does, never by locale.
     */
    public static function canonicalJson(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return Js::number($value);
        }
        if (is_string($value)) {
            return Js::string($value);
        }
        if (is_array($value) && array_is_list($value)) {
            return '['.implode(',', array_map(self::canonicalJson(...), $value)).']';
        }

        $record = $value instanceof stdClass ? get_object_vars($value) : (array) $value;
        $keys = array_map('strval', array_keys($record));
        usort($keys, Js::compare(...));
        $fields = [];
        foreach ($keys as $key) {
            $fields[] = Js::string($key).':'.self::canonicalJson($record[$key]);
        }

        return '{'.implode(',', $fields).'}';
    }

    /** SHA-256 of the canonical entry, as 64 lowercase hex characters. @param array<string, mixed> $entry */
    public static function digest(array $entry): string
    {
        return hash('sha256', self::canonicalJson(self::canonicalEntry($entry)));
    }
}

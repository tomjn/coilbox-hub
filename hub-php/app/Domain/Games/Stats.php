<?php

namespace App\Domain\Games;

/**
 * What a unit's stats say, in the words a page prints, as `lib/games/stats.ts`
 * (#227).
 *
 * The stats blob is whatever the extraction measured, schemaless by design.
 * This prints the keys it has been told about in the words players use, and
 * prints everything else as it arrived rather than hiding it.
 *
 * A JSON object and an empty JSON list both decode to an empty PHP array, so an
 * empty value prints as `[]` here.
 */
final class Stats
{
    /**
     * The keys with names of their own, in reading order. A player asks what it
     * costs and how hard it dies first, so those lead. Everything else follows
     * alphabetically.
     */
    private const KNOWN = [
        'health' => 'Health',
        'metalCost' => 'Metal cost',
        'energyCost' => 'Energy cost',
        'buildTime' => 'Build time',
        'sightDistance' => 'Sight range',
        'maxVelocity' => 'Top speed',
    ];

    /** The name a stat is printed under. An unknown key prints as itself. */
    public static function statLabel(string $key): string
    {
        return self::KNOWN[$key] ?? $key;
    }

    /**
     * One row of the table: the key in reading order, then everything else
     * alphabetically.
     *
     * @param  array<array-key, mixed>  $stats
     * @return list<array{key: string, label: string}>
     */
    public static function statRows(array $stats): array
    {
        $rows = [];
        foreach (self::KNOWN as $key => $label) {
            if (array_key_exists($key, $stats)) {
                $rows[] = ['key' => $key, 'label' => $label];
            }
        }
        $rest = [];
        foreach (array_keys($stats) as $key) {
            $key = (string) $key;
            if (! isset(self::KNOWN[$key])) {
                $rest[] = $key;
            }
        }
        foreach (Js::sortCodeUnits($rest) as $key) {
            $rows[] = ['key' => $key, 'label' => self::statLabel($key)];
        }

        return $rows;
    }

    /**
     * One value as a cell prints it. Absent stays absent, as a dash, because
     * zero would claim the unit cannot take a hit. Structures print as compact
     * JSON.
     */
    public static function formatStatValue(mixed $value): string
    {
        return match (true) {
            $value === null => '-',
            is_string($value) => $value,
            is_int($value), is_float($value) => Js::number($value),
            is_bool($value) => $value ? 'true' : 'false',
            default => self::json($value),
        };
    }

    /**
     * Whether two releases' values for one stat differ. Two values are the same
     * when they print the same.
     */
    public static function statValueChanged(mixed $left, mixed $right): bool
    {
        return self::formatStatValue($left) !== self::formatStatValue($right);
    }

    /**
     * The rows for two stat blobs: every key either side carries, in reading
     * order, with both values formatted and the ones that differ marked.
     *
     * @param  array<array-key, mixed>  $left
     * @param  array<array-key, mixed>  $right
     * @return list<array{key: string, label: string, left: string, right: string, changed: bool}>
     */
    public static function compareStatRows(array $left, array $right): array
    {
        return array_map(fn (array $row) => [
            'key' => $row['key'],
            'label' => $row['label'],
            'left' => self::formatStatValue($left[$row['key']] ?? null),
            'right' => self::formatStatValue($right[$row['key']] ?? null),
            'changed' => self::statValueChanged($left[$row['key']] ?? null, $right[$row['key']] ?? null),
        ], self::statRows($left + $right));
    }

    /**
     * The rows of a tabular stat value, or null when one does not apply. A
     * weapons summary is a list of records. Anything else stays with
     * {@see formatStatValue()}.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function tabularStatRows(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return null;
        }
        foreach ($value as $item) {
            // An empty array is an empty record, as it decodes from `{}`.
            if (! is_array($item) || (array_is_list($item) && $item !== [])) {
                return null;
            }
        }

        return $value;
    }

    /**
     * Every column these rows carry, in first-appearance order.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    public static function tabularColumns(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $key = (string) $key;
                if (! in_array($key, $columns, true)) {
                    $columns[] = $key;
                }
            }
        }

        return $columns;
    }

    /** `JSON.stringify` of a decoded value, numbers written the way JavaScript writes them. */
    private static function json(mixed $value): string
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
            return Js::stringify($value);
        }
        if (array_is_list($value)) {
            return '['.implode(',', array_map(self::json(...), $value)).']';
        }
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = Js::stringify((string) $key).':'.self::json($item);
        }

        return '{'.implode(',', $parts).'}';
    }
}

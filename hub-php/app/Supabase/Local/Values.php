<?php

namespace App\Supabase\Local;

use Carbon\CarbonImmutable;
use stdClass;

/**
 * Values between PostgREST's JSON and MariaDB columns. Reads come back the way
 * PostgREST writes them, so code and pages cannot tell which database
 * answered: timestamps as `2026-11-19T10:00:00.123+00:00`, jsonb with its keys
 * in Postgres order, booleans and integers as themselves.
 */
final class Values
{
    /** A stored value as PostgREST would answer with it. */
    public static function read(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'bool' => (bool) $value,
            'json' => json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR),
            'ts' => self::timestamp((string) $value),
            default => (string) $value,
        };
    }

    /** A value from a request or a copy, ready to store. */
    public static function write(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'bool' => is_string($value) ? in_array(strtolower($value), ['true', 't', '1'], true) : (bool) $value,
            'json' => self::json($value),
            'ts' => self::stored($value),
            default => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
        };
    }

    /** Now, to the microsecond, in the form stored. */
    public static function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
    }

    /**
     * jsonb text: object keys sorted shortest first, then by byte, the order
     * Postgres keeps them in, with a repeated key keeping its last value.
     */
    public static function json(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, false);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        return json_encode(self::sortKeys($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** @return string `Y-m-d H:i:s.u` in UTC */
    public static function stored(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value)->utc()->format('Y-m-d H:i:s.u');
    }

    /** Postgres's own JSON form for a timestamptz: microseconds without trailing zeros. */
    public static function timestamp(string $stored): string
    {
        $time = CarbonImmutable::parse($stored, 'UTC');
        $fraction = rtrim($time->format('u'), '0');

        return $time->format('Y-m-d\TH:i:s').($fraction !== '' ? '.'.$fraction : '').'+00:00';
    }

    /**
     * One level of an object in jsonb's key order: shortest first, then by
     * byte.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function jsonbKeys(array $row): array
    {
        uksort($row, fn ($a, $b) => strlen((string) $a) <=> strlen((string) $b) ?: strcmp((string) $a, (string) $b));

        return $row;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
            $object = true;
        } elseif (is_array($value) && ! array_is_list($value)) {
            $object = true;
        } elseif (is_array($value)) {
            return array_map(self::sortKeys(...), $value);
        } else {
            return $value;
        }
        $value = self::jsonbKeys($value);
        $sorted = new stdClass;
        foreach ($value as $key => $item) {
            $sorted->{$key} = self::sortKeys($item);
        }

        return $object ? $sorted : $value;
    }
}

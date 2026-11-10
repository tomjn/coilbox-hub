<?php

namespace App\Domain\Assets;

use stdClass;

/**
 * The JavaScript behaviours the asset routes' body parsing depends on, so the
 * same body is refused with the same message as in the Next app.
 */
final class Js
{
    /** The characters `String.prototype.trim()` removes. */
    private const SPACE = '[\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    /**
     * `JSON.parse`, keeping objects as `stdClass` so `{}` and `[]` stay
     * different. False when the text is not JSON.
     */
    public static function parse(string $raw, mixed &$out): bool
    {
        try {
            $out = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);

            return true;
        } catch (\JsonException) {
            return false;
        }
    }

    /** `value.trim().length`, counted in UTF-16 code units. */
    public static function trimmedLength(string $value): int
    {
        $trimmed = (string) preg_replace('/^'.self::SPACE.'+|'.self::SPACE.'+$/u', '', $value);

        return intdiv(strlen(mb_convert_encoding($trimmed, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * `Object.keys()`: keys that read as array indexes first, in number order,
     * then the rest in the order they arrived.
     *
     * @return list<string>
     */
    public static function keys(stdClass $record): array
    {
        $indexes = [];
        $names = [];
        foreach (array_keys(get_object_vars($record)) as $key) {
            $key = (string) $key;
            if (preg_match('/^(0|[1-9]\d*)$/D', $key) && (float) $key < 4294967295) {
                $indexes[] = $key;
            } else {
                $names[] = $key;
            }
        }
        usort($indexes, fn (string $a, string $b) => (float) $a <=> (float) $b);

        return [...$indexes, ...$names];
    }

    /** The first key not in `$allowed`, or null. @param list<string> $allowed */
    public static function unknownField(stdClass $record, array $allowed): ?string
    {
        foreach (self::keys($record) as $key) {
            if (! in_array($key, $allowed, true)) {
                return $key;
            }
        }

        return null;
    }

    /** A property, or null when absent. `undefined` and `null` differ in JS, so see {@see has()}. */
    public static function get(stdClass $record, string $field): mixed
    {
        return property_exists($record, $field) ? $record->{$field} : null;
    }

    /** Whether a field is present and not `undefined`. JSON has no `undefined`, so present means set. */
    public static function has(stdClass $record, string $field): bool
    {
        return property_exists($record, $field);
    }

    public static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /** `Number.isSafeInteger()`. */
    public static function isSafeInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return abs($value) <= 9007199254740991;
        }

        return is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= 9007199254740991;
    }
}

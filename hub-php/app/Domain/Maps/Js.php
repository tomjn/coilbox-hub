<?php

namespace App\Domain\Maps;

use stdClass;

/**
 * The few JavaScript behaviours the map code depends on for exact answers.
 *
 * The facts digest and the length limits were defined against JavaScript
 * strings and numbers. A digest computed here has to equal the one the Next
 * app stored, so the string forms must match to the byte.
 */
final class Js
{
    /** The characters `String.prototype.trim()` removes. */
    private const SPACE = '[\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    public static function trim(string $value): string
    {
        return (string) preg_replace('/^'.self::SPACE.'+|'.self::SPACE.'+$/u', '', $value);
    }

    /** A string's `.length`, which counts UTF-16 code units. */
    public static function length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** `.slice(0, $units)`, in UTF-16 code units. A pair cut in half loses its first half too. */
    public static function slice(string $value, int $units): string
    {
        $utf16 = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
        if (strlen($utf16) <= $units * 2) {
            return $value;
        }
        $cut = substr($utf16, 0, $units * 2);
        $last = unpack('v', substr($cut, -2))[1];
        if ($last >= 0xD800 && $last <= 0xDBFF) {
            $cut = substr($cut, 0, -2);
        }

        return mb_convert_encoding($cut, 'UTF-8', 'UTF-16LE');
    }

    /** Code unit order, which is what `Array.prototype.sort()` and `<` use on strings. */
    public static function compare(string $left, string $right): int
    {
        return strcmp(mb_convert_encoding($left, 'UTF-16BE', 'UTF-8'), mb_convert_encoding($right, 'UTF-16BE', 'UTF-8'));
    }

    public static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    public static function isRecord(mixed $value): bool
    {
        return $value instanceof stdClass;
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
            if (preg_match('/^(0|[1-9]\d*)$/', $key) && (float) $key < 4294967295) {
                $indexes[] = $key;
            } else {
                $names[] = $key;
            }
        }
        usort($indexes, fn (string $a, string $b) => (float) $a <=> (float) $b);

        return [...$indexes, ...$names];
    }

    /** `Number.isInteger()`. */
    public static function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }

    /** `Number.isSafeInteger()`. */
    public static function isSafeInteger(mixed $value): bool
    {
        return self::isInteger($value) && abs($value) <= 9007199254740991;
    }

    /** `JSON.stringify()` of a number: the shortest digits that read back the same, in JavaScript's layout. */
    public static function number(int|float $value): string
    {
        if (is_int($value)) {
            $value = (float) $value;
        }
        if ($value == 0.0) {
            return '0';
        }
        $text = json_encode($value);
        preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', (string) $text, $m);
        $sign = $m[1];
        $whole = $m[2];
        $fraction = $m[3] ?? '';
        $exponent = (int) ($m[4] ?? 0);

        // The digits with no point, and where the point sits after the first digit.
        $digits = ltrim($whole.$fraction, '0');
        $point = strlen($whole) + $exponent - (strlen($whole.$fraction) - strlen($digits));
        $digits = rtrim($digits, '0');
        $k = strlen($digits);
        $n = $point;

        if ($k <= $n && $n <= 21) {
            return $sign.$digits.str_repeat('0', $n - $k);
        }
        if (0 < $n && $n <= 21) {
            return $sign.substr($digits, 0, $n).'.'.substr($digits, $n);
        }
        if (-6 < $n && $n <= 0) {
            return $sign.'0.'.str_repeat('0', -$n).$digits;
        }
        $e = $n - 1;
        $mantissa = $k === 1 ? $digits : $digits[0].'.'.substr($digits, 1);

        return $sign.$mantissa.'e'.($e >= 0 ? '+' : '-').abs($e);
    }

    /** `JSON.stringify()` of a string. */
    public static function string(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    /**
     * Decode a request body the way `JSON.parse` would, keeping objects as
     * `stdClass` so `{}` and `[]` stay different. Returns false when it is not
     * JSON, with the value in `$out`.
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
}

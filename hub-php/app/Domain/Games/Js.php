<?php

namespace App\Domain\Games;

use Collator;

/**
 * The few JavaScript string and number rules the Next app's games code leans
 * on, so a port gives the same answer. PHP's own `trim`, `strlen` and `sort`
 * differ from JavaScript's in ways a digest or a length check notices.
 */
final class Js
{
    /** JavaScript's `String.prototype.trim`: Unicode white space and line terminators, not only ASCII. */
    public static function trim(string $value): string
    {
        return (string) preg_replace('/^[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', $value);
    }

    /** JavaScript's `.length`: UTF-16 code units. */
    public static function length(string $value): int
    {
        return intdiv(strlen((string) mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** `toLowerCase()`. */
    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /** `Array.prototype.sort()` with no comparator: UTF-16 code unit order. */
    public static function compareCodeUnits(string $left, string $right): int
    {
        return strcmp(
            (string) mb_convert_encoding($left, 'UTF-16BE', 'UTF-8'),
            (string) mb_convert_encoding($right, 'UTF-16BE', 'UTF-8'),
        ) <=> 0;
    }

    /** @param list<string> $values @return list<string> */
    public static function sortCodeUnits(array $values): array
    {
        usort($values, self::compareCodeUnits(...));

        return $values;
    }

    /** `left.localeCompare(right)` in the English locale. */
    public static function localeCompare(string $left, string $right): int
    {
        static $collator = null;
        $collator ??= new Collator('en');

        return (int) $collator->compare($left, $right) <=> 0;
    }

    /** `encodeURIComponent`, which leaves `!'()*` alone where `rawurlencode` does not. */
    public static function encodeURIComponent(string $value): string
    {
        return strtr(rawurlencode($value), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
    }

    /**
     * `new URLSearchParams(pairs).toString()`: form encoding, with `*-._`
     * left alone and a space written as `+`.
     *
     * @param  array<string, string>  $pairs
     */
    public static function searchParams(array $pairs): string
    {
        $parts = [];
        foreach ($pairs as $key => $value) {
            $parts[] = self::formEncode((string) $key).'='.self::formEncode((string) $value);
        }

        return implode('&', $parts);
    }

    private static function formEncode(string $value): string
    {
        return strtr(rawurlencode($value), ['%20' => '+', '%2A' => '*', '~' => '%7E']);
    }

    /** `decodeURIComponent`, or null where JavaScript would throw. */
    public static function decodeURIComponent(string $value): ?string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $value)) {
            return null;
        }
        $decoded = rawurldecode($value);

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : null;
    }

    /** `new Intl.ListFormat("en", { type })`: "a", "a or b", "a, b, or c". */
    public static function listFormat(array $items, string $type = 'conjunction'): string
    {
        $word = $type === 'disjunction' ? 'or' : 'and';
        $count = count($items);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return (string) $items[0];
        }
        if ($count === 2) {
            return $items[0].' '.$word.' '.$items[1];
        }

        return implode(', ', array_slice($items, 0, -1)).', '.$word.' '.$items[$count - 1];
    }

    /**
     * `JSON.stringify` for one string or number. Numbers come out the way
     * JavaScript writes a double: `890` for 890.0, `1e+21`, `1e-7`, `0.000001`.
     */
    public static function stringify(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return self::number($value);
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    /**
     * `canonicalJson` from `lib/maps/facts.ts`: keys sorted by code unit at
     * every level, numbers as JavaScript writes them, null never absent. The
     * unit facts digest is a hash of this string, so it has to match the Next
     * app byte for byte or every unit reads as changed.
     *
     * Objects arrive as `stdClass` (from `json_decode` without the assoc flag)
     * or as associative arrays. A list array is a JSON array.
     */
    public static function canonicalJson(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return self::stringify($value);
        }
        if (is_array($value) && array_is_list($value)) {
            return '['.implode(',', array_map(self::canonicalJson(...), $value)).']';
        }
        $record = (array) $value;
        $keys = self::sortCodeUnits(array_map('strval', array_keys($record)));
        $fields = [];
        foreach ($keys as $key) {
            $fields[] = self::stringify($key).':'.self::canonicalJson($record[$key]);
        }

        return '{'.implode(',', $fields).'}';
    }

    /** `String(n)` for a double, ECMAScript's Number::toString. */
    public static function number(int|float $value): string
    {
        $float = (float) $value;
        if (is_nan($float) || is_infinite($float)) {
            return 'null';
        }
        if ($float == 0.0) {
            return '0';
        }
        if (is_int($value) && abs($value) < 2 ** 53) {
            return (string) $value;
        }

        // The shortest digits that read back as the same double, then laid out
        // by JavaScript's rules rather than PHP's.
        $repr = sprintf('%.17e', $float);
        foreach (range(1, 17) as $precision) {
            $candidate = sprintf('%.'.($precision - 1).'e', $float);
            if ((float) $candidate === $float) {
                $repr = $candidate;
                break;
            }
        }
        $negative = $float < 0;
        [$mantissa, $exponent] = explode('e', ltrim($repr, '-'));
        $digits = rtrim(str_replace('.', '', $mantissa), '0');
        $digits = $digits === '' ? '0' : $digits;
        $k = strlen($digits);
        $n = (int) $exponent + 1;

        if ($k <= $n && $n <= 21) {
            $out = $digits.str_repeat('0', $n - $k);
        } elseif (0 < $n && $n <= 21) {
            $out = substr($digits, 0, $n).'.'.substr($digits, $n);
        } elseif (-6 < $n && $n <= 0) {
            $out = '0.'.str_repeat('0', -$n).$digits;
        } else {
            $e = $n - 1;
            $sign = $e < 0 ? '-' : '+';
            $out = ($k === 1 ? $digits : $digits[0].'.'.substr($digits, 1)).'e'.$sign.abs($e);
        }

        return ($negative ? '-' : '').$out;
    }
}

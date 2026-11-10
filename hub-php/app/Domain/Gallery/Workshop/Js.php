<?php

namespace App\Domain\Gallery\Workshop;

use App\Domain\Container\Container;
use stdClass;

/**
 * The few JavaScript behaviours the workshop modules lean on, so the PHP ports
 * read the same as the TypeScript they come from.
 */
final class Js
{
    /** The characters JavaScript's `\s` and `trim()` treat as white space. */
    public const WS = '\t\n\x{0B}\x{0C}\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** `typeof x === "number"`. */
    public static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /** `Number.isInteger`. */
    public static function isInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        return is_float($value) && is_finite($value) && floor($value) === $value;
    }

    /** A decoded JSON object, which `typeof x === "object" && !Array.isArray(x)` accepts. */
    public static function isRecord(mixed $value): bool
    {
        return $value instanceof stdClass;
    }

    /** `Object.keys` and `Object.entries` order: integer-like keys ascending, then the rest as written. @return list<array{string, mixed}> */
    public static function entries(mixed $value): array
    {
        $ints = [];
        $rest = [];
        foreach (Container::props($value) as $key => $item) {
            if (is_int($key) && $key >= 0 && $key <= 4294967294) {
                $ints[$key] = $item;
            } else {
                $rest[] = [(string) $key, $item];
            }
        }
        ksort($ints);
        $out = [];
        foreach ($ints as $key => $item) {
            $out[] = [(string) $key, $item];
        }

        return [...$out, ...$rest];
    }

    /** Code point order, which is also UTF-8 byte order. */
    public static function compareKeys(string|int $a, string|int $b): int
    {
        return strcmp((string) $a, (string) $b);
    }

    /** A map with its keys in code point order. @return list<array{string, mixed}> */
    public static function sorted(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            $out[] = [(string) $key, $value];
        }
        usort($out, fn ($a, $b) => strcmp($a[0], $b[0]));

        return $out;
    }

    /** `structuredClone` for decoded JSON. */
    public static function copy(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => self::copy($item), $value);
        }
        if ($value instanceof stdClass) {
            $out = new stdClass;
            foreach (get_object_vars($value) as $key => $item) {
                $out->{(string) $key} = self::copy($item);
            }

            return $out;
        }

        return $value;
    }

    /** `String.prototype.trim`. */
    public static function trim(string $text): string
    {
        return (string) preg_replace('/^['.self::WS.']+|['.self::WS.']+$/u', '', $text);
    }

    /** `Number(text)` for a string. */
    public static function toNumber(string $text): float
    {
        $t = self::trim($text);
        if ($t === '') {
            return 0.0;
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/D', $t)) {
            return (float) $t;
        }
        if (preg_match('/^([+-]?)Infinity$/D', $t, $m)) {
            return $m[1] === '-' ? -INF : INF;
        }
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/D', $t, $m)) {
            return hexdec($m[1]);
        }
        if (preg_match('/^0[oO]([0-7]+)$/D', $t, $m)) {
            return octdec($m[1]);
        }
        if (preg_match('/^0[bB]([01]+)$/D', $t, $m)) {
            return bindec($m[1]);
        }

        return NAN;
    }
}

<?php

namespace App\Domain\Gallery\Workshop;

use App\Domain\Container\Container;
use App\Domain\Gallery\Art\Palette;
use stdClass;

/**
 * Writing Lua source out of decoded JSON values, ported from
 * `lib/workshop/lua.ts`, which follows coilbox's `lua.rs`: bare keys where Lua
 * allows one, double quoted strings, a trailing comma on every entry, a
 * sequence written with its indices, and a table of nothing but scalars kept
 * on one line up to sixty columns.
 *
 * Values are what `Json::decode()` gives: `stdClass` for an object and a list
 * for an array.
 */
final class Lua
{
    public const INDENT = '  ';

    /** Width at which a table of nothing but scalars stays on one line. */
    private const INLINE_WIDTH = 60;

    private const KEYWORDS = [
        'and', 'break', 'do', 'else', 'elseif', 'end', 'false', 'for', 'function',
        'if', 'in', 'local', 'nil', 'not', 'or', 'repeat', 'return', 'then', 'true',
        'until', 'while',
    ];

    /** Control characters become three digit escapes. The padding is needed, or `\0` then `5` reads back as byte 5. */
    public static function string(string $value): string
    {
        $out = '"';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $ch = $value[$i];
            $code = ord($ch);
            if ($ch === '\\') {
                $out .= '\\\\';
            } elseif ($ch === '"') {
                $out .= '\\"';
            } elseif ($ch === "\n") {
                $out .= '\\n';
            } elseif ($ch === "\r") {
                $out .= '\\r';
            } elseif ($ch === "\t") {
                $out .= '\\t';
            } elseif ($code < 0x20 || $code === 0x7F) {
                $out .= '\\'.str_pad((string) $code, 3, '0', STR_PAD_LEFT);
            } else {
                $out .= $ch;
            }
        }

        return $out.'"';
    }

    /** Whether a key can be a bare table key. `repeat` is both a keyword and a real field name. */
    private static function isIdentifier(string $key): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) === 1 && ! in_array($key, self::KEYWORDS, true);
    }

    /** A key Lua indexes as a number. `["1"]` is a different key from `[1]`. */
    private static function integerKey(string $key): ?string
    {
        if (preg_match('/^-?(0|[1-9][0-9]{0,14})$/D', $key) !== 1 || $key === '-0') {
            return null;
        }

        return $key;
    }

    public static function tableKey(string $key): string
    {
        if (self::isIdentifier($key)) {
            return $key;
        }
        $n = self::integerKey($key);

        return $n === null ? '['.self::string($key).']' : '['.$n.']';
    }

    /** JavaScript's `String(n)`, with only the `+` of `1e+21` removed. */
    private static function number(int|float $value): string
    {
        return str_replace('e+', 'e', Palette::num($value));
    }

    public static function isTable(mixed $value): bool
    {
        return Container::isObject($value);
    }

    /** The shared table body, so an array, an object and a patch are laid out the same. @param list<string> $parts */
    public static function renderTable(array $parts, bool $allScalars, string $indent): string
    {
        if ($parts === []) {
            return '{}';
        }
        $inline = '{ '.implode(', ', $parts).' }';
        if ($allScalars && mb_strlen($inline, 'UTF-8') + mb_strlen($indent, 'UTF-8') <= self::INLINE_WIDTH) {
            return $inline;
        }
        $inner = $indent.self::INDENT;

        return "{\n".implode("\n", array_map(fn ($part) => $inner.$part.',', $parts))."\n".$indent.'}';
    }

    /** A JSON value as a Lua literal, one table entry per line. An object's keys are sorted. */
    public static function literal(mixed $value, string $indent): string
    {
        if ($value === null) {
            return 'nil';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return self::number($value);
        }
        if (is_string($value)) {
            return self::string($value);
        }

        $inner = $indent.self::INDENT;
        $entries = [];
        if (is_array($value)) {
            foreach (array_values($value) as $i => $item) {
                $entries[] = ['['.($i + 1).']', $item];
            }
        } elseif ($value instanceof stdClass) {
            foreach (Js::sorted(get_object_vars($value)) as [$key, $item]) {
                $entries[] = [self::tableKey($key), $item];
            }
        }

        return self::renderTable(
            array_map(fn ($entry) => $entry[0].' = '.self::literal($entry[1], $inner), $entries),
            ! array_filter($entries, fn ($entry) => self::isTable($entry[1])),
            $indent,
        );
    }
}

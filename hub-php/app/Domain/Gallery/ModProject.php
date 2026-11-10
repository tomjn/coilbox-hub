<?php

namespace App\Domain\Gallery;

use stdClass;

/**
 * How much a unit tweak project changes, from the Next app's
 * `lib/gallery/modProjectPreview.ts`. Reads the five stores under
 * `payload.edits`, which is also the shape the card's batched lookup asks
 * PostgREST for. The counting functions take decoded JSON as associative
 * arrays or as `stdClass` objects. {@see details} needs the `stdClass` form,
 * from `Json::decode()`, because Lua output tells `{}` from `[]`.
 */
final class ModProject
{
    /**
     * How many rows the item page's changelog draws before folding the rest
     * into a count. A full roster rebalance can touch every unit in a game, so
     * the page caps the list and says how many more there are.
     */
    public const CHANGELOG_ROW_LIMIT = 200;

    private const INDENT = '  ';

    /** Width at which a Lua table of nothing but scalars stays on one line. */
    private const INLINE_WIDTH = 60;

    private const LUA_KEYWORDS = [
        'and', 'break', 'do', 'else', 'elseif', 'end', 'false', 'for', 'function',
        'if', 'in', 'local', 'nil', 'not', 'or', 'repeat', 'return', 'then', 'true',
        'until', 'while',
    ];

    /**
     * @return array{overrides: array<string, int>, text: array<string, int>, clones: array<string, string|null>, menus: array<string, int>, disabled: array<string, true>}
     */
    public static function parseEdits(mixed $value): array
    {
        $source = self::record($value);

        return [
            'overrides' => self::fieldCounts($source['overrides'] ?? null),
            'text' => self::textCounts($source['text'] ?? null),
            'clones' => self::clones($source['clones'] ?? null),
            'menus' => self::menuCounts($source['menus'] ?? null),
            'disabled' => self::disabled($source['disabled'] ?? null),
        ];
    }

    /**
     * @param  array{overrides: array<string, int>, text: array<string, int>, clones: array<string, string|null>, menus: array<string, int>, disabled: array<string, true>}  $edits
     * @return array{unitsTouched: int, fields: int, clones: int, menuOps: int, disabled: int}
     */
    public static function counts(array $edits): array
    {
        return [
            'unitsTouched' => count(array_keys($edits['overrides'] + $edits['text'])),
            'fields' => array_sum($edits['overrides']) + array_sum($edits['text']),
            'clones' => count($edits['clones']),
            'menuOps' => array_sum($edits['menus']),
            'disabled' => count($edits['disabled']),
        ];
    }

    /** The counts a project's card opens with, read straight off a payload. */
    public static function countsFromPayload(mixed $payload): array
    {
        return self::counts(self::parseEdits(self::record($payload)['edits'] ?? null));
    }

    /** A JSON object, not a list. An empty array reads as an object, since a payload decoded to arrays cannot say which it was. */
    private static function record(mixed $value): ?array
    {
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }

        return is_array($value) && ($value === [] || ! array_is_list($value)) ? $value : null;
    }

    /** Trimmed and lower cased, as every store keys itself in Coilbox. */
    private static function unitKey(string|int $raw): ?string
    {
        $key = mb_strtolower(trim((string) $raw));

        return $key === '' ? null : $key;
    }

    private static function fieldCounts(mixed $value): array
    {
        $out = [];
        foreach (self::record($value) ?? [] as $raw => $fields) {
            $key = self::unitKey($raw);
            $record = self::record($fields);
            if ($key !== null && $record !== null && count($record) > 0) {
                $out[$key] = count($record);
            }
        }

        return $out;
    }

    private static function textCounts(mixed $value): array
    {
        $out = [];
        foreach (self::record($value) ?? [] as $raw => $languages) {
            $key = self::unitKey($raw);
            $record = self::record($languages);
            if ($key === null || $record === null) {
                continue;
            }
            $count = 0;
            foreach ($record as $fields) {
                $entry = self::record($fields);
                if ($entry !== null) {
                    $count += count($entry);
                }
            }
            if ($count > 0) {
                $out[$key] = $count;
            }
        }

        return $out;
    }

    private static function clones(mixed $value): array
    {
        $out = [];
        foreach (self::record($value) ?? [] as $raw => $entry) {
            $key = self::unitKey($raw);
            $clone = self::record($entry);
            if ($key !== null && $clone !== null) {
                $out[$key] = is_string($clone['source'] ?? null) ? $clone['source'] : null;
            }
        }

        return $out;
    }

    private static function menuCounts(mixed $value): array
    {
        $out = [];
        foreach (self::record($value) ?? [] as $raw => $ops) {
            $key = self::unitKey($raw);
            if ($key !== null && is_array($ops) && array_is_list($ops) && count($ops) > 0) {
                $out[$key] = count($ops);
            }
        }

        return $out;
    }

    private static function disabled(mixed $value): array
    {
        $out = [];
        if (! is_array($value) || ! array_is_list($value)) {
            return $out;
        }
        foreach ($value as $entry) {
            if (is_string($entry) && ($key = self::unitKey($entry)) !== null) {
                $out[$key] = true;
            }
        }

        return $out;
    }

    /**
     * Every unit the changelog has something to say about, in alphabetical
     * order, as rows of `unit`, `fields`, `textFields`, `added`, `source`,
     * `disabled` and `menuOps`.
     *
     * @param  array{overrides: array<string, int>, text: array<string, int>, clones: array<string, string|null>, menus: array<string, int>, disabled: array<string, true>}  $edits
     * @return list<array{unit: string, fields: int, textFields: int, added: bool, source: string|null, disabled: bool, menuOps: int}>
     */
    public static function changelogFromParsed(array $edits): array
    {
        $units = array_map('strval', array_keys(
            $edits['overrides'] + $edits['text'] + $edits['clones'] + $edits['menus'] + $edits['disabled']
        ));
        self::sortNames($units);

        return array_map(fn (string $unit) => [
            'unit' => $unit,
            'fields' => $edits['overrides'][$unit] ?? 0,
            'textFields' => $edits['text'][$unit] ?? 0,
            'added' => array_key_exists($unit, $edits['clones']),
            'source' => $edits['clones'][$unit] ?? null,
            'disabled' => isset($edits['disabled'][$unit]),
            'menuOps' => $edits['menus'][$unit] ?? 0,
        ], $units);
    }

    /** The item page's changelog, read straight off a container payload's `edits`. */
    public static function changelog(mixed $payload): array
    {
        return self::changelogFromParsed(self::parseEdits(self::record($payload)['edits'] ?? null));
    }

    /** The scale of a whole project in one sentence, or an empty string. */
    public static function describeProject(array $counts): string
    {
        $n = fn (int $count, string $noun) => $count.' '.$noun.($count === 1 ? '' : 's');
        $parts = [];
        if ($counts['fields'] > 0) {
            $parts[] = 'changes '.$n($counts['fields'], 'field').' on '.$n($counts['unitsTouched'], 'unit');
        }
        if ($counts['clones'] > 0) {
            $parts[] = 'adds '.$n($counts['clones'], 'unit');
        }
        if ($counts['menuOps'] > 0) {
            $parts[] = 'makes '.$n($counts['menuOps'], 'build menu edit');
        }
        if ($counts['disabled'] > 0) {
            $parts[] = 'switches off '.$n($counts['disabled'], 'unit');
        }
        if ($parts === []) {
            return '';
        }
        $sentence = count($parts) === 1
            ? $parts[0]
            : implode(', ', array_slice($parts, 0, -1)).' and '.$parts[count($parts) - 1];

        return mb_strtoupper(mb_substr($sentence, 0, 1)).mb_substr($sentence, 1).'.';
    }

    /**
     * The values behind every row of the changelog, keyed the way the changelog
     * keys its units. Each entry holds `fields` as [path, Lua source] pairs in
     * path order, `text` as [language, field, words] and `menu` as operations
     * with `op`, `unit` and, for a move, `before`. Takes a payload decoded with
     * `Json::decode()`. An entry that is not the shape Coilbox writes is left
     * out.
     *
     * @return array<string, array{fields: list<array{string, string}>, text: list<array{string, string, string}>, menu: list<array<string, string|null>>}>
     */
    public static function details(mixed $payload): array
    {
        $edits = self::object(self::object($payload)['edits'] ?? null);
        $out = [];
        $detail = function (string|int $raw) use (&$out): ?string {
            $key = self::unitKey($raw);
            if ($key === null) {
                return null;
            }
            $out[$key] ??= ['fields' => [], 'text' => [], 'menu' => []];

            return $key;
        };

        foreach (self::object($edits['overrides'] ?? null) ?? [] as $raw => $fields) {
            $record = self::object($fields);
            $key = $record === null ? null : $detail($raw);
            if ($key === null) {
                continue;
            }
            $paths = array_map('strval', array_keys($record));
            sort($paths, SORT_STRING);
            foreach ($paths as $path) {
                $out[$key]['fields'][] = [$path, self::fieldValue($record[$path])];
            }
        }
        foreach (self::object($edits['text'] ?? null) ?? [] as $raw => $languages) {
            $record = self::object($languages);
            $key = $record === null ? null : $detail($raw);
            if ($key === null) {
                continue;
            }
            foreach ($record as $language => $fields) {
                foreach (self::object($fields) ?? [] as $field => $words) {
                    if (is_string($words)) {
                        $out[$key]['text'][] = [(string) $language, (string) $field, $words];
                    }
                }
            }
        }
        foreach (self::object($edits['menus'] ?? null) ?? [] as $raw => $ops) {
            $parsed = self::menuOps($ops);
            $key = $parsed === [] ? null : $detail($raw);
            if ($key !== null) {
                array_push($out[$key]['menu'], ...$parsed);
            }
        }

        return $out;
    }

    /** A JSON object only: `stdClass`, or a non empty associative array. */
    private static function object(mixed $value): ?array
    {
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }

        return is_array($value) && $value !== [] && ! array_is_list($value) ? $value : null;
    }

    private static function menuOps(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            $op = self::object($entry);
            if ($op === null || ! is_string($op['unit'] ?? null)) {
                continue;
            }
            $kind = $op['op'] ?? null;
            if ($kind === 'add' || $kind === 'remove') {
                $out[] = ['op' => $kind, 'unit' => $op['unit']];
            } elseif ($kind === 'move') {
                $out[] = ['op' => 'move', 'unit' => $op['unit'], 'before' => is_string($op['before'] ?? null) ? $op['before'] : null];
            }
        }

        return $out;
    }

    /** Alphabetical, as JavaScript's `localeCompare` orders unit names. */
    private static function sortNames(array &$names): void
    {
        if (class_exists(\Collator::class)) {
            (new \Collator('en_US'))->sort($names);

            return;
        }
        sort($names, SORT_STRING);
    }

    /**
     * Ported from `luaLiteral` in the Next app's `lib/workshop/lua.ts`, which
     * another port covers in its own class. Only what the changelog needs is
     * here. A table of nothing but scalars stays on one line up to sixty
     * columns, a sequence is written with its indices, and an object's keys are
     * sorted by byte, which is code point order for UTF-8.
     */
    private static function fieldValue(mixed $value): string
    {
        return self::luaLiteral($value, '');
    }

    private static function luaLiteral(mixed $value, string $indent): string
    {
        if ($value === null) {
            return 'nil';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return self::luaNumber($value);
        }
        if (is_string($value)) {
            return self::luaString($value);
        }

        $inner = $indent.self::INDENT;
        $entries = [];
        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $i => $item) {
                $entries[] = ['['.($i + 1).']', $item];
            }
        } else {
            $props = self::record($value) ?? [];
            $keys = array_map('strval', array_keys($props));
            sort($keys, SORT_STRING);
            foreach ($keys as $key) {
                $entries[] = [self::luaKey($key), $props[$key]];
            }
        }
        $parts = array_map(fn ($entry) => $entry[0].' = '.self::luaLiteral($entry[1], $inner), $entries);
        $scalars = array_reduce($entries, fn ($all, $entry) => $all && ! (is_array($entry[1]) || is_object($entry[1])), true);
        if ($parts === []) {
            return '{}';
        }
        $inline = '{ '.implode(', ', $parts).' }';
        if ($scalars && mb_strlen($inline) + mb_strlen($indent) <= self::INLINE_WIDTH) {
            return $inline;
        }

        return "{\n".implode("\n", array_map(fn ($part) => $inner.$part.',', $parts))."\n".$indent.'}';
    }

    private static function luaString(string $value): string
    {
        $out = '"';
        foreach (str_split($value) as $byte) {
            $code = ord($byte);
            $out .= match (true) {
                $byte === '\\' => '\\\\',
                $byte === '"' => '\\"',
                $byte === "\n" => '\\n',
                $byte === "\r" => '\\r',
                $byte === "\t" => '\\t',
                $code < 0x20 || $code === 0x7F => '\\'.str_pad((string) $code, 3, '0', STR_PAD_LEFT),
                default => $byte,
            };
        }

        return $out.'"';
    }

    private static function luaKey(string $key): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1 && ! in_array($key, self::LUA_KEYWORDS, true)) {
            return $key;
        }
        if (preg_match('/^-?(0|[1-9][0-9]{0,14})$/', $key) === 1 && $key !== '-0') {
            return '['.$key.']';
        }

        return '['.self::luaString($key).']';
    }

    /** A float the way JavaScript's `String(n)` writes it, minus the `+` of an exponent. */
    private static function luaNumber(float $value): string
    {
        if ($value == 0) {
            return '0';
        }
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', json_encode($value), $m) !== 1) {
            return (string) $value;
        }
        $sign = $m[1];
        $point = strlen($m[2]) + (int) ($m[4] ?? 0);
        $digits = $m[2].($m[3] ?? '');
        $trimmed = ltrim($digits, '0');
        $point -= strlen($digits) - strlen($trimmed);
        $digits = rtrim($trimmed, '0');
        $k = strlen($digits);

        if ($k <= $point && $point <= 21) {
            return $sign.$digits.str_repeat('0', $point - $k);
        }
        if (0 < $point && $point <= 21) {
            return $sign.substr($digits, 0, $point).'.'.substr($digits, $point);
        }
        if (-6 < $point && $point <= 0) {
            return $sign.'0.'.str_repeat('0', -$point).$digits;
        }
        $exponent = $point - 1;
        $mantissa = $k === 1 ? $digits : $digits[0].'.'.substr($digits, 1);

        return $sign.$mantissa.'e'.($exponent < 0 ? '-' : '').abs($exponent);
    }
}

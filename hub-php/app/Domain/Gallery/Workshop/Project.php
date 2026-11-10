<?php

namespace App\Domain\Gallery\Workshop;

use RuntimeException;
use stdClass;

/**
 * A published project, read as strictly as coilbox's compiler reads one,
 * ported from `lib/workshop/project.ts`. Anything serde would reject comes back
 * as null, because the hub offers no commands for a project it cannot read in
 * full.
 *
 * The result is an array:
 *   name: string
 *   edits: overrides (unit => path => value), clones (id => clone), menus
 *          (unit => list of ops), textEdits (int), disabled (list of string)
 *   readOnlyLua: list of {title, lua, form: 'block'|'unrecognised'|null}
 * A clone is {key, source: ?string, replacesGameUnit: bool, def: mixed}. An op
 * is {op: 'add'|'remove'|'move', unit, before?: ?string}.
 */
final class Project
{
    /** @return array{name: string, edits: array, readOnlyLua: list<array>}|null */
    public static function read(mixed $payload): ?array
    {
        try {
            $source = self::object($payload);
            // Read only to refuse what coilbox would refuse.
            self::optionalText(self::get($source, 'description'));
            self::field($source, 'gameName', fn ($v) => self::text($v), '');

            return [
                'name' => self::field($source, 'name', fn ($v) => self::text($v), ''),
                'edits' => self::has($source, 'edits') ? self::edits($source->edits) : self::edits(new stdClass),
                'readOnlyLua' => self::field($source, 'readOnlyLua', fn ($v) => self::listOf($v, function ($block) {
                    $b = self::object($block);
                    // The note is read to refuse what coilbox would refuse, then dropped.
                    self::text(self::get($b, 'note'));
                    $form = self::get($b, 'form');

                    return [
                        'title' => self::text(self::get($b, 'title')),
                        'lua' => self::text(self::get($b, 'lua')),
                        // Only the decoder's own two words are permission to compile it.
                        'form' => $form === 'block' || $form === 'unrecognised' ? $form : null,
                    ];
                }), []),
            ];
        } catch (Malformed) {
            return null;
        }
    }

    private static function fail(): never
    {
        throw new Malformed;
    }

    private static function object(mixed $value): stdClass
    {
        return $value instanceof stdClass ? $value : self::fail();
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : self::fail();
    }

    private static function optionalText(mixed $value): ?string
    {
        return $value === null ? null : self::text($value);
    }

    private static function has(stdClass $object, string $key): bool
    {
        return property_exists($object, $key);
    }

    private static function get(stdClass $object, string $key): mixed
    {
        return $object->{$key} ?? null;
    }

    /** A field serde gives a default when it is absent. null is not absent. */
    private static function field(stdClass $object, string $key, \Closure $read, mixed $otherwise): mixed
    {
        return self::has($object, $key) ? $read($object->{$key}) : $otherwise;
    }

    /** @return array<string, mixed> in the order JavaScript would walk the keys */
    private static function mapOf(mixed $value, \Closure $read): array
    {
        $out = [];
        foreach (Js::entries(self::object($value)) as [$key, $item]) {
            $out[$key] = $read($item);
        }

        return $out;
    }

    private static function listOf(mixed $value, \Closure $read): array
    {
        return is_array($value) ? array_map($read, array_values($value)) : self::fail();
    }

    private static function clone(mixed $value): array
    {
        $source = self::object($value);

        return [
            'key' => self::text(self::get($source, 'key')),
            'source' => self::optionalText(self::get($source, 'source')),
            'replacesGameUnit' => self::field($source, 'replacesGameUnit', fn ($v) => is_bool($v) ? $v : self::fail(), false),
            'def' => self::get($source, 'def'),
        ];
    }

    private static function menuOp(mixed $value): array
    {
        $source = self::object($value);
        $unit = self::text(self::get($source, 'unit'));
        $op = self::get($source, 'op');
        if ($op === 'add' || $op === 'remove') {
            return ['op' => $op, 'unit' => $unit];
        }
        if ($op === 'move') {
            return ['op' => 'move', 'unit' => $unit, 'before' => self::optionalText(self::get($source, 'before'))];
        }

        return self::fail();
    }

    private static function textEditCount(mixed $value): int
    {
        $count = 0;
        foreach (self::mapOf($value, fn ($v) => self::mapOf($v, fn ($f) => self::object($f))) as $languages) {
            foreach ($languages as $fields) {
                if (self::optionalText(self::get($fields, 'name')) !== null) {
                    $count++;
                }
                if (self::optionalText(self::get($fields, 'description')) !== null) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private static function edits(mixed $value): array
    {
        $source = self::object($value);

        return [
            'overrides' => self::field($source, 'overrides', fn ($v) => self::mapOf($v, fn ($patch) => self::mapOf($patch, fn ($leaf) => $leaf)), []),
            'clones' => self::field($source, 'clones', fn ($v) => self::mapOf($v, fn ($c) => self::clone($c)), []),
            'menus' => self::field($source, 'menus', fn ($v) => self::mapOf($v, fn ($ops) => self::listOf($ops, fn ($op) => self::menuOp($op))), []),
            'textEdits' => self::field($source, 'text', fn ($v) => self::textEditCount($v), 0),
            'disabled' => self::field($source, 'disabled', fn ($v) => self::listOf($v, fn ($u) => self::text($u)), []),
        ];
    }
}

/** Raised when a payload is not a project coilbox could read. */
final class Malformed extends RuntimeException {}

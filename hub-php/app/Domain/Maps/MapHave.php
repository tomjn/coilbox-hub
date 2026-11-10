<?php

namespace App\Domain\Maps;

use stdClass;

/**
 * The wire shape of the map have check, `lib/api/mapHave.ts`. A client sends
 * the facts it holds for each map and hears which of them the hub still wants.
 * Results are in request order, so a caller zips by index.
 */
final class MapHave
{
    public const FORMAT = 'coilbox-hub-map-have';

    public const VERSION = 1;

    private const BODY_FIELDS = ['keys'];

    private const KEY_FIELDS = ['map_name', 'source_hash', 'catalog_version'];

    private const MAX_LENGTHS = ['map_name' => 256, 'source_hash' => 128];

    public static function maxKeys(): int
    {
        return Catalog::caps()['haveKeys'];
    }

    /**
     * Strict, like the asset check: an unknown field on the body or on any key
     * is a 400, because an ignored misspelling would read as a missing corpus.
     * Over the limit is a 413, since the fix is to split the batch.
     *
     * @return array{ok: true, keys: list<array{mapName: string, sourceHash: string, catalogVersion: int}>}|array{ok: false, error: string, status: int}
     */
    public static function parse(mixed $body): array
    {
        if (! Js::isRecord($body)) {
            return ['ok' => false, 'error' => 'The request body must be a JSON object.', 'status' => 400];
        }
        $extra = self::unknownField($body, self::BODY_FIELDS);
        if ($extra !== null) {
            return ['ok' => false, 'error' => 'Unknown field: '.$extra, 'status' => 400];
        }

        $raw = $body->keys ?? null;
        if (! is_array($raw)) {
            return ['ok' => false, 'error' => '`keys` is required and must be an array.', 'status' => 400];
        }
        if ($raw === []) {
            return ['ok' => false, 'error' => '`keys` must not be empty.', 'status' => 400];
        }
        $max = self::maxKeys();
        if (count($raw) > $max) {
            return ['ok' => false, 'error' => 'A batch may carry at most '.$max.' keys. That request carried '.count($raw).'. Split it.', 'status' => 413];
        }

        $keys = [];
        $seen = [];
        foreach ($raw as $index => $value) {
            $parsed = self::parseKey($value);
            if (is_string($parsed)) {
                return ['ok' => false, 'error' => 'keys['.$index.'] '.$parsed, 'status' => 400];
            }
            // One canonical name is one archive, so a name twice is a client
            // that has misread its own map list.
            if (isset($seen[$parsed['mapName']])) {
                return ['ok' => false, 'error' => 'keys['.$index.'] repeats a key already in the batch.', 'status' => 400];
            }
            $seen[$parsed['mapName']] = true;
            $keys[] = $parsed;
        }

        return ['ok' => true, 'keys' => $keys];
    }

    /**
     * Missing when the hub has no row. Changed when the archive differs, or when
     * the same archive was read by a newer extraction. Have otherwise, so an old
     * build never talks the catalog backwards.
     *
     * @param  array{mapName: string, sourceHash: string, catalogVersion: int}  $key
     * @param  array<string, array{sourceHash: string, catalogVersion: int}>  $held
     */
    public static function status(array $key, array $held): string
    {
        $stored = $held[$key['mapName']] ?? null;
        if ($stored === null) {
            return 'missing';
        }
        if ($stored['sourceHash'] !== $key['sourceHash']) {
            return 'changed';
        }

        return $key['catalogVersion'] > $stored['catalogVersion'] ? 'changed' : 'have';
    }

    /**
     * @param  list<array{mapName: string, sourceHash: string, catalogVersion: int}>  $keys
     * @param  array<string, array{sourceHash: string, catalogVersion: int}>  $held
     * @return array{format: string, version: int, results: list<array{map_name: string, status: string}>}
     */
    public static function body(array $keys, array $held): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'results' => array_map(fn (array $key) => ['map_name' => $key['mapName'], 'status' => self::status($key, $held)], $keys),
        ];
    }

    /** @return array{mapName: string, sourceHash: string, catalogVersion: int}|string */
    private static function parseKey(mixed $value): array|string
    {
        if (! Js::isRecord($value)) {
            return 'must be a JSON object.';
        }
        $extra = self::unknownField($value, self::KEY_FIELDS);
        if ($extra !== null) {
            return 'unknown field: '.$extra;
        }

        $fields = [];
        foreach (['map_name', 'source_hash'] as $field) {
            $text = $value->{$field} ?? null;
            $length = is_string($text) ? Js::length(Js::trim($text)) : 0;
            if (! is_string($text) || $length < 1 || $length > self::MAX_LENGTHS[$field]) {
                return '`'.$field.'` is required and must be a string of 1 to '.self::MAX_LENGTHS[$field].' characters.';
            }
            $fields[$field] = $text;
        }

        $catalogVersion = $value->catalog_version ?? null;
        if (! Js::isInteger($catalogVersion) || $catalogVersion < 1) {
            return '`catalog_version` is required and must be an integer of 1 or more.';
        }

        return ['mapName' => $fields['map_name'], 'sourceHash' => $fields['source_hash'], 'catalogVersion' => $catalogVersion];
    }

    /** @param list<string> $allowed */
    private static function unknownField(stdClass $record, array $allowed): ?string
    {
        foreach (Js::keys($record) as $field) {
            if (! in_array($field, $allowed, true)) {
                return $field;
            }
        }

        return null;
    }
}

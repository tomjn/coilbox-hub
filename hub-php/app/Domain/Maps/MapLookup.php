<?php

namespace App\Domain\Maps;

/**
 * The wire shape of the map lookup, `lib/api/mapLookup.ts`: given canonical
 * map names, what the hub knows about each. A name the hub does not know is
 * null in a 200, the ordinary answer while the catalog fills up.
 *
 * A repeated name is answered twice rather than refused. A lobby list names the
 * map each game plays, and several games play one map.
 */
final class MapLookup
{
    public const FORMAT = 'coilbox-hub-map-lookup';

    public const VERSION = 1;

    private const MAX_NAME_LENGTH = 256;

    public static function maxNames(): int
    {
        return Catalog::caps()['lookupNames'];
    }

    /** @return array{ok: true, names: list<string>}|array{ok: false, error: string, status: int} */
    public static function parse(mixed $body): array
    {
        if (! Js::isRecord($body)) {
            return ['ok' => false, 'error' => 'The request body must be a JSON object.', 'status' => 400];
        }
        foreach (Js::keys($body) as $field) {
            if ($field !== 'names') {
                return ['ok' => false, 'error' => 'Unknown field: '.$field, 'status' => 400];
            }
        }

        $raw = $body->names ?? null;
        if (! is_array($raw)) {
            return ['ok' => false, 'error' => '`names` is required and must be an array.', 'status' => 400];
        }
        if ($raw === []) {
            return ['ok' => false, 'error' => '`names` must not be empty.', 'status' => 400];
        }
        $max = self::maxNames();
        if (count($raw) > $max) {
            return ['ok' => false, 'error' => 'A batch may carry at most '.$max.' names. That request carried '.count($raw).'. Split it.', 'status' => 413];
        }

        $names = [];
        foreach ($raw as $index => $value) {
            // Measured trimmed, as the column's check is. The untrimmed name is looked up.
            $length = is_string($value) ? Js::length(Js::trim($value)) : 0;
            if (! is_string($value) || $length < 1 || $length > self::MAX_NAME_LENGTH) {
                return ['ok' => false, 'error' => 'names['.$index.'] must be a string of 1 to '.self::MAX_NAME_LENGTH.' characters.', 'status' => 400];
            }
            $names[] = $value;
        }

        return ['ok' => true, 'names' => $names];
    }

    /**
     * One result per name the request listed, in that order.
     *
     * @param  list<string>  $names
     * @param  array<string, mixed>  $found
     * @return array<string, mixed>
     */
    public static function body(array $names, array $found): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'results' => array_map(fn (string $name) => ['map_name' => $name, 'map' => $found[$name] ?? null], $names),
        ];
    }
}

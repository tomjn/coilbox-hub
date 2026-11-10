<?php

namespace App\Domain\Maps;

use stdClass;

/**
 * The wire shape of a map submission, `lib/api/mapSubmit.ts`. A client sends
 * what it read out of its archives and the hub answers what it did with each.
 *
 * A malformed entry is a `refused` result rather than a 400 for the batch, so
 * one bad map does not fail the other forty nine. Unknown fields are refused
 * rather than ignored, so a misspelt `source_hash` is never silently dropped.
 * `slug`, `facts_digest` and author keys are never accepted: the hub works them
 * out itself.
 */
final class MapSubmit
{
    public const FORMAT = 'coilbox-hub-maps';

    public const VERSION = 1;

    private const BODY_FIELDS = ['format', 'version', 'maps'];

    private const ENTRY_FIELDS = [
        'map_name', 'display_name', 'description', 'map_version', 'author', 'archive_filename',
        'source_archive', 'source_hash', 'catalog_version', 'width_elmos', 'height_elmos',
        'world_height_min', 'world_height_max', 'min_wind', 'max_wind', 'tidal_strength',
        'void_water', 'void_ground', 'water_coverage', 'appearance', 'points',
    ];

    private const POINT_KINDS = ['start', 'metal', 'geo'];

    private const POINT_FIELDS = ['x', 'z', 'y', 'meta'];

    /** The lengths `public.map` accepts. `author` is a whole credit string, so longer than one column. */
    private const MAX_LENGTHS = [
        'map_name' => 256,
        'display_name' => 256,
        'description' => 4000,
        'map_version' => 64,
        'author' => 1024,
        'archive_filename' => 256,
        'source_archive' => 256,
        'source_hash' => 128,
    ];

    public static function maxMaps(): int
    {
        return Catalog::caps()['submitMaps'];
    }

    public static function maxBytes(): int
    {
        return Catalog::caps()['submitBytes'];
    }

    /**
     * The batch. Only a few things refuse the whole request: a body that is not
     * an object, an envelope the hub does not speak, an unknown body field, an
     * empty or oversized batch, and one name twice.
     *
     * @return array{ok: true, entries: list<array{ok: true, entry: array<string, mixed>}|array{ok: false, mapName: string, said: string}>}|array{ok: false, error: string, status: int}
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
        if (($body->format ?? null) !== self::FORMAT) {
            return ['ok' => false, 'error' => '`format` must be "'.self::FORMAT.'".', 'status' => 400];
        }
        $version = $body->version ?? null;
        if (! Js::isNumber($version) || $version != self::VERSION) {
            return ['ok' => false, 'error' => '`version` must be '.self::VERSION.'. This hub speaks no other.', 'status' => 400];
        }

        $raw = $body->maps ?? null;
        if (! is_array($raw)) {
            return ['ok' => false, 'error' => '`maps` is required and must be an array.', 'status' => 400];
        }
        if ($raw === []) {
            return ['ok' => false, 'error' => '`maps` must not be empty.', 'status' => 400];
        }
        $max = self::maxMaps();
        if (count($raw) > $max) {
            return ['ok' => false, 'error' => 'A batch may carry at most '.$max.' maps. That request carried '.count($raw).'. Split it.', 'status' => 413];
        }

        $entries = [];
        $seen = [];
        foreach ($raw as $index => $value) {
            $label = self::labelOf($value);
            if ($label !== '') {
                if (isset($seen[$label])) {
                    return ['ok' => false, 'error' => 'maps['.$index.'] names a map already in the batch.', 'status' => 400];
                }
                $seen[$label] = true;
            }

            try {
                $entries[] = ['ok' => true, 'entry' => self::parseEntry($value)];
            } catch (Refused $refused) {
                $entries[] = ['ok' => false, 'mapName' => $label, 'said' => $refused->getMessage()];
            }
        }

        return ['ok' => true, 'entries' => $entries];
    }

    /**
     * @param  list<array{map_name: string, outcome: string, said?: string}>  $results
     * @return array{format: string, version: int, results: list<array{map_name: string, outcome: string, said?: string}>}
     */
    public static function body(array $results): array
    {
        return ['format' => self::FORMAT, 'version' => self::VERSION, 'results' => $results];
    }

    /**
     * One map's facts. The cross field rules at the end are the table's own
     * constraints, checked here so the client hears which pair disagrees.
     *
     * @return array<string, mixed>
     */
    private static function parseEntry(mixed $value): array
    {
        if (! Js::isRecord($value)) {
            throw new Refused('A map entry must be a JSON object.');
        }
        $extra = self::unknownField($value, self::ENTRY_FIELDS);
        if ($extra !== null) {
            throw new Refused('Unknown field: '.$extra);
        }

        $mapName = self::readText($value, 'map_name');
        $sourceArchive = self::readText($value, 'source_archive');
        $sourceHash = self::readText($value, 'source_hash');
        $catalogVersion = self::readCount($value, 'catalog_version');
        $width = self::readCount($value, 'width_elmos');
        $height = self::readCount($value, 'height_elmos');
        $worldHeightMin = self::readMeasure($value, 'world_height_min');
        $worldHeightMax = self::readMeasure($value, 'world_height_max');
        $displayName = self::optionalText($value, 'display_name');
        $description = self::optionalText($value, 'description');
        $mapVersion = self::optionalText($value, 'map_version');
        $author = self::optionalText($value, 'author');
        $archiveFilename = self::optionalText($value, 'archive_filename');
        $minWind = self::optionalMeasure($value, 'min_wind');
        $maxWind = self::optionalMeasure($value, 'max_wind');
        $tidalStrength = self::optionalMeasure($value, 'tidal_strength');
        $voidWater = self::optionalFlag($value, 'void_water');
        $voidGround = self::optionalFlag($value, 'void_ground');
        $waterCoverage = self::optionalMeasure($value, 'water_coverage');
        $appearance = self::readAppearance($value);
        $points = self::readPoints($value->points ?? null);

        if ($worldHeightMax < $worldHeightMin) {
            throw new Refused('`world_height_max` cannot be below `world_height_min`.');
        }
        if ($minWind !== null && $maxWind !== null && $maxWind < $minWind) {
            throw new Refused('`max_wind` cannot be below `min_wind`.');
        }
        if ($waterCoverage !== null && ($waterCoverage < 0 || $waterCoverage > 1)) {
            throw new Refused('`water_coverage` is a share of the map between 0 and 1, not a percentage.');
        }
        if ($voidWater === true && $waterCoverage !== null) {
            throw new Refused('A map with `void_water` has no water to report a `water_coverage` for.');
        }

        return Facts::canonicalEntry([
            'map_name' => $mapName,
            'display_name' => $displayName,
            'description' => $description,
            'map_version' => $mapVersion,
            'author' => $author,
            'archive_filename' => $archiveFilename,
            'source_archive' => $sourceArchive,
            'source_hash' => $sourceHash,
            'catalog_version' => $catalogVersion,
            'width_elmos' => $width,
            'height_elmos' => $height,
            'world_height_min' => $worldHeightMin,
            'world_height_max' => $worldHeightMax,
            'min_wind' => $minWind,
            'max_wind' => $maxWind,
            'tidal_strength' => $tidalStrength,
            'void_water' => $voidWater,
            'void_ground' => $voidGround,
            'water_coverage' => $waterCoverage,
            'appearance' => $appearance,
            'points' => $points,
        ]);
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

    /**
     * A required string, measured after trimming the way the table's checks
     * measure it. The untrimmed value is kept, because `map_identity_idx` is on
     * the stored text.
     */
    private static function readText(stdClass $record, string $field): string
    {
        $value = $record->{$field} ?? null;
        $length = is_string($value) ? Js::length(Js::trim($value)) : 0;
        if (! is_string($value) || $length < 1 || $length > self::MAX_LENGTHS[$field]) {
            throw new Refused('`'.$field.'` is required and must be a string of 1 to '.self::MAX_LENGTHS[$field].' characters.');
        }

        return $value;
    }

    /** An optional string. Blank reads as absent, and the trimmed value is stored. */
    private static function optionalText(stdClass $record, string $field): ?string
    {
        $value = $record->{$field} ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || Js::length(Js::trim($value)) > self::MAX_LENGTHS[$field]) {
            throw new Refused('`'.$field.'` must be a string of at most '.self::MAX_LENGTHS[$field].' characters.');
        }
        $trimmed = Js::trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /** A required positive integer, what the table's `check (x > 0)` columns hold. */
    private static function readCount(stdClass $record, string $field): int
    {
        $value = $record->{$field} ?? null;
        if (! Js::isSafeInteger($value) || $value < 1) {
            throw new Refused('`'.$field.'` is required and must be a positive integer.');
        }

        return (int) $value;
    }

    /** A required finite number. Heights can be negative or a zero wide range. */
    private static function readMeasure(stdClass $record, string $field): int|float
    {
        $value = $record->{$field} ?? null;
        if (! Js::isNumber($value) || ! is_finite((float) $value)) {
            throw new Refused('`'.$field.'` is required and must be a number.');
        }

        return $value;
    }

    /** A measurement mapinfo may leave out. Absent stays absent, never a zero. */
    private static function optionalMeasure(stdClass $record, string $field): int|float|null
    {
        $value = $record->{$field} ?? null;
        if ($value === null) {
            return null;
        }
        if (! Js::isNumber($value) || ! is_finite((float) $value)) {
            throw new Refused('`'.$field.'` must be a number.');
        }

        return $value;
    }

    private static function optionalFlag(stdClass $record, string $field): ?bool
    {
        $value = $record->{$field} ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_bool($value)) {
            throw new Refused('`'.$field.'` must be true or false.');
        }

        return $value;
    }

    /**
     * One point. `meta` passes through unchecked: the hub reads none of it, and
     * a rule here could only refuse a field a newer client started sending.
     *
     * @return array{x: int|float, z: int|float, y: int|float|null, meta: stdClass|null}
     */
    private static function readPoint(mixed $value, string $where): array
    {
        try {
            if (! Js::isRecord($value)) {
                throw new Refused('must be a JSON object.');
            }
            $extra = self::unknownField($value, self::POINT_FIELDS);
            if ($extra !== null) {
                throw new Refused('unknown field: '.$extra);
            }
            $x = self::readMeasure($value, 'x');
            $z = self::readMeasure($value, 'z');
            $y = self::optionalMeasure($value, 'y');
            $meta = $value->meta ?? null;
            if ($meta !== null && ! Js::isRecord($meta)) {
                throw new Refused('`meta` must be a JSON object.');
            }
        } catch (Refused $refused) {
            throw new Refused($where.' '.$refused->getMessage());
        }

        return ['x' => $x, 'z' => $z, 'y' => $y, 'meta' => $meta];
    }

    /**
     * The three kinds of point. Absent gives three empty lists. An unknown kind
     * is refused, because `map_point.kind` refuses it too.
     *
     * @return array{start: list<array<string, mixed>>, metal: list<array<string, mixed>>, geo: list<array<string, mixed>>}
     */
    private static function readPoints(mixed $value): array
    {
        $points = ['start' => [], 'metal' => [], 'geo' => []];
        if ($value === null) {
            return $points;
        }
        if (! Js::isRecord($value)) {
            throw new Refused('`points` must be a JSON object.');
        }
        $extra = self::unknownField($value, self::POINT_KINDS);
        if ($extra !== null) {
            throw new Refused('`points` names a kind the hub does not know: '.$extra);
        }

        foreach (self::POINT_KINDS as $kind) {
            $raw = $value->{$kind} ?? null;
            if ($raw === null) {
                continue;
            }
            if (! is_array($raw)) {
                throw new Refused('`points.'.$kind.'` must be an array.');
            }
            foreach ($raw as $index => $entry) {
                $points[$kind][] = self::readPoint($entry, '`points.'.$kind.'['.$index.']`');
            }
        }

        return $points;
    }

    /** Everything only the 3D view reads. Empty rather than null, matching the column default. */
    private static function readAppearance(stdClass $record): stdClass
    {
        $value = $record->appearance ?? null;
        if ($value === null) {
            return new stdClass;
        }
        if (! Js::isRecord($value)) {
            throw new Refused('`appearance` must be a JSON object.');
        }

        return $value;
    }

    /** The name a malformed entry is answered under: whatever of it can be echoed back. */
    private static function labelOf(mixed $value): string
    {
        if (! Js::isRecord($value)) {
            return '';
        }
        $mapName = $value->map_name ?? null;
        if (! is_string($mapName)) {
            return '';
        }
        $length = Js::length(Js::trim($mapName));

        return $length > 0 && $length <= self::MAX_LENGTHS['map_name'] ? $mapName : '';
    }
}

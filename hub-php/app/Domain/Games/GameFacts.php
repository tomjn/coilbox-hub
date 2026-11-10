<?php

namespace App\Domain\Games;

use stdClass;

/**
 * The wire shape of a game facts submission, `lib/api/gameFacts.ts`. A client
 * with the game archive mounted sends what it read out of it, one game at one
 * release per request, and the hub answers what it did with each entry.
 *
 * Parse the body with `json_decode($raw)` (objects as `stdClass`), so an empty
 * `stats` object stays an object and digests the way the Next app's did.
 *
 * An unknown field anywhere is refused naming the field, because a client
 * that misspelled one and had it dropped would resubmit forever. A client
 * never sends `facts_digest`: the hub computes it.
 *
 * `downloads` is the one field that is not a fact. It is an instruction to
 * fetch and run code, so it goes through the edit form's own check and is
 * offered for a person to accept rather than written.
 */
final class GameFacts
{
    public const FORMAT = 'coilbox-hub-games';

    public const VERSION = 1;

    public const MAX_BYTES = 2_000_000;

    public const MAX_UNITS = 2_000;

    public const MAX_FACTIONS = 64;

    public const MAX_LINKS = 12;

    private const BODY_FIELDS = ['format', 'version', 'shortname', 'release', 'complete', 'startUnits', 'factions', 'units', 'name', 'description', 'baseGame', 'links', 'downloads'];

    private const LINK_FIELDS = ['label', 'url'];

    private const DOWNLOAD_FIELDS = ['kind', 'value', 'asset', 'filename'];

    private const FACTION_FIELDS = ['key', 'name'];

    private const UNIT_FIELDS = ['name', 'fullName', 'factionKey', 'buildOptions', 'stats', 'morphTargets'];

    private const MAX_SHORTNAME = 64;

    private const MAX_RELEASE = 64;

    private const MAX_FACTION_KEY = 128;

    private const MAX_FACTION_NAME = 256;

    private const MAX_UNIT_NAME = 128;

    private const MAX_FULL_NAME = 256;

    private const MAX_BUILD_OPTION = 128;

    private const MAX_DISPLAY_NAME = 256;

    private const MAX_BASE_GAME = 64;

    private const MAX_DESCRIPTION = 4000;

    /** Stats render as a table, not a dataset, so a runaway extractor is refused naming the unit. */
    private const MAX_STATS_JSON = 8_192;

    private const MAX_MORPH_JSON = 8_192;

    /**
     * The whole request, normalised. `complete` defaults to false, and false
     * removes nothing, so a partial backfill cannot retire what it did not
     * mention. Absent factions leave the held set alone for the same reason.
     *
     * @return array{ok: true, submission: array}|array{ok: false, error: string, status: int}
     */
    public static function parse(mixed $body): array
    {
        if (! $body instanceof stdClass) {
            return self::fail('The request body must be a JSON object.');
        }
        $record = get_object_vars($body);

        $extra = self::unknownField($record, self::BODY_FIELDS);
        if ($extra !== null) {
            return self::fail('unknown field: '.$extra);
        }
        if (($record['format'] ?? null) !== self::FORMAT) {
            return self::fail('Send facts as format "'.self::FORMAT.'".');
        }
        $version = $record['version'] ?? null;
        if (! ((is_int($version) || is_float($version)) && $version == self::VERSION)) {
            return self::fail('This endpoint speaks format version '.self::VERSION.'.');
        }

        $shortname = self::readText($record, 'shortname', self::MAX_SHORTNAME);
        if (! $shortname['ok']) {
            return self::fail($shortname['error']);
        }
        $release = self::readText($record, 'release', self::MAX_RELEASE);
        if (! $release['ok']) {
            return self::fail($release['error']);
        }

        $complete = false;
        if (($record['complete'] ?? null) !== null) {
            if (! is_bool($record['complete'])) {
                return self::fail('`complete` must be true or false.');
            }
            $complete = $record['complete'];
        }

        $startUnits = null;
        if (($record['startUnits'] ?? null) !== null) {
            if (! self::isList($record['startUnits'])) {
                return self::fail('`startUnits` must be an array of unit names.');
            }
            if (count($record['startUnits']) > self::MAX_FACTIONS) {
                return self::fail('`startUnits` is implausibly long.');
            }
            $startUnits = [];
            foreach ($record['startUnits'] as $root) {
                if (! self::textWithin($root, self::MAX_UNIT_NAME)) {
                    return self::fail('`startUnits` entries must be strings of 1 to '.self::MAX_UNIT_NAME.' characters.');
                }
                $startUnits[] = Js::trim($root);
            }
        }

        $factions = null;
        if (($record['factions'] ?? null) !== null) {
            if (! self::isList($record['factions'])) {
                return self::fail('`factions` must be an array.');
            }
            if (count($record['factions']) > self::MAX_FACTIONS) {
                return self::fail('A request may carry at most '.self::MAX_FACTIONS.' factions.', 413);
            }
            $factions = [];
            foreach ($record['factions'] as $faction) {
                $parsed = self::readFaction($faction);
                if (! $parsed['ok']) {
                    return self::fail('A faction '.$parsed['error']);
                }
                $factions[] = $parsed['value'];
            }
        }

        if (! self::isList($record['units'] ?? null)) {
            return self::fail('`units` is required and must be an array.');
        }
        if (count($record['units']) > self::MAX_UNITS) {
            return self::fail('A request may carry at most '.self::MAX_UNITS.' units.', 413);
        }
        $units = [];
        foreach ($record['units'] as $unit) {
            $parsed = self::readUnit($unit);
            if (! $parsed['ok']) {
                return self::fail('A unit '.$parsed['error']);
            }
            $units[] = $parsed['value'];
        }

        $displayName = self::optionalText($record, 'name', self::MAX_DISPLAY_NAME);
        if (! $displayName['ok']) {
            return self::fail($displayName['error']);
        }
        $description = self::optionalText($record, 'description', self::MAX_DESCRIPTION);
        if (! $description['ok']) {
            return self::fail($description['error']);
        }
        $baseGame = self::optionalText($record, 'baseGame', self::MAX_BASE_GAME);
        if (! $baseGame['ok']) {
            return self::fail($baseGame['error']);
        }
        if ($baseGame['value'] === $shortname['value']) {
            return self::fail('`baseGame` must name a different game from `shortname`.');
        }

        $links = self::readLinks($record);
        if (! $links['ok']) {
            return self::fail($links['error']);
        }
        $downloads = self::readDownloadSources($record);
        if (! $downloads['ok']) {
            return self::fail($downloads['error']);
        }

        return ['ok' => true, 'submission' => [
            'shortname' => $shortname['value'],
            'release' => $release['value'],
            'complete' => $complete,
            'start_units' => $startUnits,
            'factions' => $factions,
            'units' => $units,
            'display_name' => $displayName['value'],
            'description' => $description['value'],
            'base_game' => $baseGame['value'],
            'links' => $links['value'],
            'downloads' => $downloads['value'],
        ]];
    }

    private static function fail(string $error, int $status = 400): array
    {
        return ['ok' => false, 'error' => $error, 'status' => $status];
    }

    private static function unknownField(array $record, array $allowed): ?string
    {
        foreach (array_keys($record) as $field) {
            if (! in_array((string) $field, $allowed, true)) {
                return (string) $field;
            }
        }

        return null;
    }

    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    private static function textWithin(mixed $value, int $max): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $length = Js::length(Js::trim($value));

        return $length >= 1 && $length <= $max;
    }

    /** A required string, measured and stored trimmed, the way the check constraints measure it. */
    private static function readText(array $record, string $field, int $max): array
    {
        $value = $record[$field] ?? null;
        if (! self::textWithin($value, $max)) {
            return ['ok' => false, 'error' => '`'.$field.'` is required and must be a string of 1 to '.$max.' characters.'];
        }

        return ['ok' => true, 'value' => Js::trim($value)];
    }

    private static function optionalText(array $record, string $field, int $max): array
    {
        $value = $record[$field] ?? null;
        if ($value === null) {
            return ['ok' => true, 'value' => null];
        }
        if (! is_string($value) || Js::length(Js::trim($value)) > $max) {
            return ['ok' => false, 'error' => '`'.$field.'` must be a string of at most '.$max.' characters.'];
        }
        $trimmed = Js::trim($value);

        return ['ok' => true, 'value' => $trimmed !== '' ? $trimmed : null];
    }

    /** Sorted and deduplicated, because the order Lua handed them over is not a fact. */
    private static function readBuildOptions(array $record): array
    {
        $value = $record['buildOptions'] ?? null;
        if ($value === null) {
            return ['ok' => true, 'value' => []];
        }
        if (! self::isList($value)) {
            return ['ok' => false, 'error' => '`buildOptions` must be an array of unit names.'];
        }
        if (count($value) > self::MAX_UNITS) {
            return ['ok' => false, 'error' => '`buildOptions` is implausibly long.'];
        }
        $seen = [];
        foreach ($value as $option) {
            if (! self::textWithin($option, self::MAX_BUILD_OPTION)) {
                return ['ok' => false, 'error' => '`buildOptions` entries must be strings of 1 to '.self::MAX_BUILD_OPTION.' characters.'];
            }
            $seen[Js::trim($option)] = true;
        }

        return ['ok' => true, 'value' => Js::sortCodeUnits(array_map('strval', array_keys($seen)))];
    }

    private static function readStats(array $record): array
    {
        $value = $record['stats'] ?? null;
        if ($value === null) {
            return ['ok' => true, 'value' => new stdClass];
        }
        if (! $value instanceof stdClass) {
            return ['ok' => false, 'error' => '`stats` must be a JSON object.'];
        }
        if (Js::length(Js::canonicalJson($value)) > self::MAX_STATS_JSON) {
            return ['ok' => false, 'error' => '`stats` holds more than '.self::MAX_STATS_JSON.' bytes of JSON.'];
        }

        return ['ok' => true, 'value' => $value];
    }

    /**
     * What a unit turns into. `into` is the only field named. The rest is the
     * game's own condition vocabulary, stored as it arrives.
     */
    private static function readMorphTargets(array $record): array
    {
        $value = $record['morphTargets'] ?? null;
        if ($value === null) {
            return ['ok' => true, 'value' => []];
        }
        if (! self::isList($value)) {
            return ['ok' => false, 'error' => '`morphTargets` must be an array of morph objects.'];
        }
        $seen = [];
        $targets = [];
        foreach ($value as $entry) {
            if (! $entry instanceof stdClass) {
                return ['ok' => false, 'error' => '`morphTargets` entries must be JSON objects.'];
            }
            $into = $entry->into ?? null;
            if (! is_string($into) || Js::trim($into) === '') {
                return ['ok' => false, 'error' => 'a `morphTargets` entry must name the unit it turns into.'];
            }
            $trimmed = Js::trim($into);
            if (Js::length($trimmed) > self::MAX_UNIT_NAME) {
                return ['ok' => false, 'error' => 'a `morphTargets` entry names a unit too long to store.'];
            }
            // Two edges to one target are one edge.
            if (isset($seen[$trimmed])) {
                continue;
            }
            $seen[$trimmed] = true;
            $target = clone $entry;
            $target->into = $trimmed;
            $targets[] = $target;
        }
        if (Js::length(Js::canonicalJson($targets)) > self::MAX_MORPH_JSON) {
            return ['ok' => false, 'error' => '`morphTargets` holds more than '.self::MAX_MORPH_JSON.' bytes of JSON.'];
        }

        return ['ok' => true, 'value' => $targets];
    }

    private static function readUnit(mixed $value): array
    {
        if (! $value instanceof stdClass) {
            return ['ok' => false, 'error' => 'must be a JSON object.'];
        }
        $record = get_object_vars($value);
        $extra = self::unknownField($record, self::UNIT_FIELDS);
        if ($extra !== null) {
            return ['ok' => false, 'error' => 'unknown field: '.$extra];
        }

        $name = self::readText($record, 'name', self::MAX_UNIT_NAME);
        if (! $name['ok']) {
            return $name;
        }
        $fullName = self::optionalText($record, 'fullName', self::MAX_FULL_NAME);
        if (! $fullName['ok']) {
            return $fullName;
        }
        $factionKey = self::optionalText($record, 'factionKey', self::MAX_FACTION_KEY);
        if (! $factionKey['ok']) {
            return $factionKey;
        }
        $buildOptions = self::readBuildOptions($record);
        if (! $buildOptions['ok']) {
            return $buildOptions;
        }
        $stats = self::readStats($record);
        if (! $stats['ok']) {
            return $stats;
        }
        $morphTargets = self::readMorphTargets($record);
        if (! $morphTargets['ok']) {
            return $morphTargets;
        }

        return ['ok' => true, 'value' => [
            'name' => $name['value'],
            'full_name' => $fullName['value'],
            'faction_key' => $factionKey['value'],
            'build_options' => $buildOptions['value'],
            'stats' => $stats['value'],
            'morph_targets' => $morphTargets['value'],
        ]];
    }

    private static function readFaction(mixed $value): array
    {
        if (! $value instanceof stdClass) {
            return ['ok' => false, 'error' => 'must be a JSON object.'];
        }
        $record = get_object_vars($value);
        $extra = self::unknownField($record, self::FACTION_FIELDS);
        if ($extra !== null) {
            return ['ok' => false, 'error' => 'unknown field: '.$extra];
        }
        $key = self::readText($record, 'key', self::MAX_FACTION_KEY);
        if (! $key['ok']) {
            return $key;
        }
        $name = self::readText($record, 'name', self::MAX_FACTION_NAME);
        if (! $name['ok']) {
            return $name;
        }

        return ['ok' => true, 'value' => ['key' => $key['value'], 'name' => $name['value']]];
    }

    private static function readLink(mixed $value): array
    {
        if (! $value instanceof stdClass) {
            return ['ok' => false, 'error' => 'must be a JSON object.'];
        }
        $record = get_object_vars($value);
        $extra = self::unknownField($record, self::LINK_FIELDS);
        if ($extra !== null) {
            return ['ok' => false, 'error' => 'unknown field: '.$extra];
        }
        $label = $record['label'] ?? null;
        if (! is_string($label) || Js::trim($label) === '') {
            return ['ok' => false, 'error' => '`label` is required and must be a non-empty string.'];
        }
        $url = $record['url'] ?? null;
        if (! is_string($url) || Js::trim($url) === '') {
            return ['ok' => false, 'error' => '`url` is required and must be a non-empty string.'];
        }

        return ['ok' => true, 'value' => ['label' => Js::trim($label), 'url' => Js::trim($url)]];
    }

    /** Absent means say nothing about the links, the rule `factions` follows. */
    private static function readLinks(array $record): array
    {
        $value = $record['links'] ?? null;
        if ($value === null) {
            return ['ok' => true, 'value' => null];
        }
        if (! self::isList($value)) {
            return ['ok' => false, 'error' => '`links` must be an array of {label, url} objects.'];
        }
        if (count($value) > self::MAX_LINKS) {
            return ['ok' => false, 'error' => '`links` may hold at most '.self::MAX_LINKS.' entries.'];
        }
        $links = [];
        foreach ($value as $entry) {
            $parsed = self::readLink($entry);
            if (! $parsed['ok']) {
                return ['ok' => false, 'error' => 'A link '.$parsed['error']];
            }
            $links[] = $parsed['value'];
        }

        return ['ok' => true, 'value' => $links];
    }

    /**
     * Where the client believes the game can be fetched from. The same
     * `parseDownload` an owner's typing goes through, so a submission cannot
     * reach the table down a path that skips a rule. A refused source is a 400
     * for the request: a client sending one has a bug worth hearing about.
     */
    private static function readDownloadSources(array $record): array
    {
        $value = $record['downloads'] ?? null;
        if ($value === null) {
            return ['ok' => true, 'value' => null];
        }
        if (! self::isList($value)) {
            return ['ok' => false, 'error' => '`downloads` must be an array of {kind, value} objects.'];
        }
        if (count($value) > Download::MAX_DOWNLOADS) {
            return ['ok' => false, 'error' => '`downloads` may hold at most '.Download::MAX_DOWNLOADS.' entries.'];
        }
        $downloads = [];
        foreach ($value as $entry) {
            if (! $entry instanceof stdClass) {
                return ['ok' => false, 'error' => 'A download must be a JSON object.'];
            }
            $fields = get_object_vars($entry);
            $extra = self::unknownField($fields, self::DOWNLOAD_FIELDS);
            if ($extra !== null) {
                return ['ok' => false, 'error' => 'A download has an unknown field: '.$extra];
            }
            $text = fn (string $key) => is_string($fields[$key] ?? null) ? $fields[$key] : '';
            $parsed = Download::parseDownload($text('kind'), $text('value'), $text('asset'), $text('filename'));
            if (! $parsed['ok']) {
                return ['ok' => false, 'error' => 'A download: '.$parsed['message']];
            }
            if ($parsed['download'] !== null) {
                $downloads[] = $parsed['download'];
            }
        }

        return ['ok' => true, 'value' => $downloads];
    }
}

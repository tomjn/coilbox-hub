<?php

namespace App\Domain\Assets;

use stdClass;

/**
 * The wire shape of the batch have check, `lib/api/assetHave.ts` in the Next
 * app. Coilbox sends identities with the `source_hash` it holds for each and
 * learns which of them the hub still wants.
 */
final class AssetHaveApi
{
    public const FORMAT = 'coilbox-hub-asset-have';

    public const VERSION = 1;

    /** Five hundred covers a whole game's buildpics. Over it is refused whole, never truncated. */
    public const MAX_KEYS = 500;

    private const FIELDS = [
        'unit' => ['keyed_on', 'game', 'unit_name', 'variant', 'source_hash'],
        'map' => ['keyed_on', 'map_name', 'variant', 'source_hash'],
        'faction' => ['keyed_on', 'game', 'faction_key', 'variant', 'source_hash'],
    ];

    /**
     * Strict about unknown fields, so `sourceHash` for `source_hash` is a 400
     * rather than a whole corpus reported missing. Over the limit is a 413.
     *
     * @return array{ok: true, keys: list<array{identity: array<string, string>, sourceHash: string}>}|array{ok: false, error: string, status: int}
     */
    public static function parse(mixed $body): array
    {
        return Batch::parse($body, self::MAX_KEYS, self::parseKey(...), fn (array $key) => $key['identity']);
    }

    /**
     * Have, changed or missing for each key, in request order. Moderation state
     * is deliberately not part of it: pending, approved and rejected all mean
     * the same thing to a caller deciding whether to upload.
     *
     * @param  list<array{identity: array<string, string>, sourceHash: string}>  $keys
     * @param  array<string, string>  $sourceHashes
     * @return array<string, mixed>
     */
    public static function body(array $keys, array $sourceHashes): array
    {
        $results = [];
        foreach ($keys as $key) {
            $stored = $sourceHashes[Have::identityKey($key['identity'])] ?? null;
            $status = $stored === null ? 'missing' : ($stored === $key['sourceHash'] ? 'have' : 'changed');
            $identity = $key['identity'];
            $results[] = match ($identity['keyedOn']) {
                'unit' => ['keyed_on' => 'unit', 'game' => $identity['game'], 'unit_name' => $identity['unitName'], 'variant' => $identity['variant'], 'status' => $status],
                'faction' => ['keyed_on' => 'faction', 'game' => $identity['game'], 'faction_key' => $identity['factionKey'], 'variant' => $identity['variant'], 'status' => $status],
                'map' => ['keyed_on' => 'map', 'map_name' => $identity['mapName'], 'variant' => $identity['variant'], 'status' => $status],
            };
        }

        return ['format' => self::FORMAT, 'version' => self::VERSION, 'results' => $results];
    }

    /** @return array{ok: true, value: array{identity: array<string, string>, sourceHash: string}}|array{ok: false, error: string} */
    private static function parseKey(mixed $value): array
    {
        if (! $value instanceof stdClass) {
            return ['ok' => false, 'error' => 'must be a JSON object.'];
        }

        $keyedOn = Js::get($value, 'keyed_on');
        if (! in_array($keyedOn, ['unit', 'map', 'faction'], true)) {
            return ['ok' => false, 'error' => '`keyed_on` must be "unit", "map" or "faction".'];
        }

        $extra = Js::unknownField($value, self::FIELDS[$keyedOn]);
        if ($extra !== null) {
            return ['ok' => false, 'error' => 'unknown field: '.$extra];
        }

        $variant = Fields::text($value, 'variant');
        if (! $variant['ok']) {
            return $variant;
        }
        $sourceHash = Fields::text($value, 'source_hash');
        if (! $sourceHash['ok']) {
            return $sourceHash;
        }

        if ($keyedOn === 'map') {
            $mapName = Fields::text($value, 'map_name');
            if (! $mapName['ok']) {
                return $mapName;
            }
            // A refusal rather than `missing`, which would send the caller off to extract and encode a typo.
            if (! Asset::isMapVariant($variant['value'])) {
                return ['ok' => false, 'error' => Fields::mapVariantError()];
            }

            return ['ok' => true, 'value' => ['identity' => AssetResolver::map($mapName['value'], $variant['value']), 'sourceHash' => $sourceHash['value']]];
        }

        $game = Fields::text($value, 'game');
        if (! $game['ok']) {
            return $game;
        }

        if ($keyedOn === 'faction') {
            $factionKey = Fields::text($value, 'faction_key');
            if (! $factionKey['ok']) {
                return $factionKey;
            }
            if ($variant['value'] !== Asset::factionLogoVariant()) {
                return ['ok' => false, 'error' => Fields::factionVariantError()];
            }

            return ['ok' => true, 'value' => ['identity' => AssetResolver::faction($game['value'], $factionKey['value'], $variant['value']), 'sourceHash' => $sourceHash['value']]];
        }

        $unitName = Fields::text($value, 'unit_name');
        if (! $unitName['ok']) {
            return $unitName;
        }
        if (! Fields::isUnitVariant($variant['value'])) {
            return ['ok' => false, 'error' => Fields::unitVariantError()];
        }

        return ['ok' => true, 'value' => ['identity' => AssetResolver::unit($game['value'], $unitName['value'], $variant['value']), 'sourceHash' => $sourceHash['value']]];
    }
}

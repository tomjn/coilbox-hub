<?php

namespace App\Domain\Assets;

use stdClass;

/**
 * The wire shape of the batch picture lookup, `lib/api/assetPictures.ts` in
 * the Next app: given an identity, what picture the hub holds and where.
 *
 * Units and maps only. A faction logo is shown from `game_faction` once
 * promotion has written it there. No `source_hash` on a key, because the
 * caller is asking about a map it has not installed and cannot hash.
 */
final class AssetPicturesApi
{
    public const FORMAT = 'coilbox-hub-asset-pictures';

    public const VERSION = 1;

    /** The same ceiling as the have check, and its own constant since either could move. */
    public const MAX_KEYS = 500;

    private const FIELDS = [
        'unit' => ['keyed_on', 'game', 'unit_name', 'variant'],
        'map' => ['keyed_on', 'map_name', 'variant'],
    ];

    /** @return array{ok: true, keys: list<array<string, string>>}|array{ok: false, error: string, status: int} */
    public static function parse(mixed $body): array
    {
        return Batch::parse($body, self::MAX_KEYS, self::parseKey(...), fn (array $identity) => $identity);
    }

    /**
     * One result per identity in request order. `picture` is null when the hub
     * holds nothing approved, which is a 200 and the ordinary answer.
     *
     * @param  list<array<string, string>>  $identities
     * @param  array<string, array<string, mixed>>  $held
     * @return array<string, mixed>
     */
    public static function body(array $identities, array $held): array
    {
        $results = [];
        foreach ($identities as $identity) {
            $picture = self::pictureFor($identity, $held);
            $results[] = $identity['keyedOn'] === 'unit'
                ? ['keyed_on' => 'unit', 'game' => $identity['game'], 'unit_name' => $identity['unitName'], 'variant' => $identity['variant'], 'picture' => $picture]
                : ['keyed_on' => 'map', 'map_name' => $identity['mapName'], 'variant' => $identity['variant'], 'picture' => $picture];
        }

        return ['format' => self::FORMAT, 'version' => self::VERSION, 'results' => $results];
    }

    /**
     * Which rung answers is the resolver's decision, so the buildpic
     * substitution and the moderation check are the website's too. The row is
     * read back for `path`, which a resolved picture does not carry.
     *
     * @param  array<string, string>  $identity
     * @param  array<string, array<string, mixed>>  $held
     * @return array<string, mixed>|null
     */
    private static function pictureFor(array $identity, array $held): ?array
    {
        $resolved = AssetResolver::resolveAsset($identity, $held);
        if ($resolved['from'] === 'placeholder') {
            return null;
        }
        $row = $held[Have::identityKey($resolved['served'])] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'tier' => $row['tier'],
            'path' => $row['path'],
            'url' => $resolved['url'],
            'width' => $resolved['width'],
            'height' => $resolved['height'],
            'served_variant' => $resolved['served']['variant'],
            'substituted' => $resolved['substituted'],
        ];
    }

    /** @return array{ok: true, value: array<string, string>}|array{ok: false, error: string} */
    private static function parseKey(mixed $value): array
    {
        if (! $value instanceof stdClass) {
            return ['ok' => false, 'error' => 'must be a JSON object.'];
        }

        $keyedOn = Js::get($value, 'keyed_on');
        if ($keyedOn !== 'unit' && $keyedOn !== 'map') {
            return ['ok' => false, 'error' => '`keyed_on` must be "unit" or "map".'];
        }

        $extra = Js::unknownField($value, self::FIELDS[$keyedOn]);
        if ($extra !== null) {
            return ['ok' => false, 'error' => 'unknown field: '.$extra];
        }

        $variant = Fields::text($value, 'variant');
        if (! $variant['ok']) {
            return $variant;
        }

        if ($keyedOn === 'map') {
            $mapName = Fields::text($value, 'map_name');
            if (! $mapName['ok']) {
                return $mapName;
            }
            if (! Asset::isMapVariant($variant['value'])) {
                return ['ok' => false, 'error' => Fields::mapVariantError()];
            }

            return ['ok' => true, 'value' => AssetResolver::map($mapName['value'], $variant['value'])];
        }

        $game = Fields::text($value, 'game');
        if (! $game['ok']) {
            return $game;
        }
        $unitName = Fields::text($value, 'unit_name');
        if (! $unitName['ok']) {
            return $unitName;
        }
        if (! Fields::isUnitVariant($variant['value'])) {
            return ['ok' => false, 'error' => Fields::unitVariantError()];
        }

        return ['ok' => true, 'value' => AssetResolver::unit($game['value'], $unitName['value'], $variant['value'])];
    }
}

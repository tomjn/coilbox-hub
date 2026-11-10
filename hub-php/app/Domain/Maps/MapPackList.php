<?php

namespace App\Domain\Maps;

/**
 * The wire shape of `GET /api/v1/map-packs`, `lib/api/mapPackList.ts`. Written
 * to coilbox's `HubMapPack`, whose maps are its `SuggestedMap`. Each map is a
 * name to search for. Taken down maps are left out, and so is a pack left with
 * no maps.
 */
final class MapPackList
{
    public const FORMAT = 'coilbox-hub-map-packs';

    public const VERSION = 1;

    /**
     * @param  list<array<string, mixed>>  $packs  from {@see Packs::fetch()}
     * @return array<string, mixed>
     */
    public static function body(array $packs): array
    {
        $out = [];
        foreach ($packs as $pack) {
            $maps = array_values(array_filter($pack['maps'], fn (array $map) => ! $map['takenDown']));
            if ($maps === []) {
                continue;
            }
            $entry = ['id' => $pack['id'], 'title' => $pack['title']];
            if (! empty($pack['blurb'])) {
                $entry['blurb'] = $pack['blurb'];
            }
            $entry['featured'] = $pack['featuredAt'] !== null;
            $entry['maps'] = array_map(function (array $map) {
                // The map name rather than the slug: unique, and still there when the catalog row is not.
                $item = [
                    'id' => $map['mapName'],
                    'title' => $map['displayName'] ?? $map['mapName'],
                    'download' => ['kind' => 'map', 'springName' => $map['mapName']],
                ];
                if (! empty($map['archiveFilename'])) {
                    $item['filename'] = $map['archiveFilename'];
                }

                return $item;
            }, $maps);
            $out[] = $entry;
        }

        return ['format' => self::FORMAT, 'version' => self::VERSION, 'packs' => $out];
    }
}

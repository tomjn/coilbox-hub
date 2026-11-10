<?php

namespace App\Domain\Maps;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * The database half of the map have check, `lib/maps/have.ts`: what facts the
 * hub already holds under each name. Only `source_hash` and `catalog_version`
 * decide a status, so only those are read.
 */
final class Have
{
    /** Names per PostgREST request. The filter is in the URL, so URL length is the limit. */
    public const NAMES_PER_QUERY = 100;

    /**
     * A PostgREST string operand, quoted and escaped. A map name is free text,
     * and a bare comma or bracket would end the filter early.
     */
    public static function operand(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    public static function nameFilter(string $mapName): string
    {
        return 'map_name.eq.'.self::operand($mapName);
    }

    /**
     * Keyed on the name exactly as stored. A name with no row is absent. Null
     * when a read fails, which the route answers with a 503.
     *
     * @param  list<string>  $mapNames
     * @return array<string, array{sourceHash: string, catalogVersion: int}>|null
     */
    public static function fetch(Client $supabase, array $mapNames): ?array
    {
        $held = [];
        try {
            foreach (array_chunk($mapNames, self::NAMES_PER_QUERY) as $chunk) {
                $rows = $supabase->from('map')
                    ->select('map_name, source_hash, catalog_version')
                    ->or(implode(',', array_map(self::nameFilter(...), $chunk)))
                    ->get();
                foreach ($rows as $row) {
                    $held[(string) $row['map_name']] = ['sourceHash' => $row['source_hash'], 'catalogVersion' => $row['catalog_version']];
                }
            }
        } catch (SupabaseException) {
            return null;
        }

        return $held;
    }
}

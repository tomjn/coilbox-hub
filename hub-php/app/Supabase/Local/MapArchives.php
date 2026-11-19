<?php

namespace App\Supabase\Local;

use App\Domain\Games\ReadAll;
use App\Supabase\Supabase;

/**
 * What the hub mirrors hold of each map, read from Supabase, where archives
 * and mirrors stay until they move (#600).
 *
 * Supabase grants no role a read of `archive`. Its own functions and the
 * `map_browse` view reach it as their owner. So the two mirror columns of
 * `map_browse` are read from that view, one request for every map, and the
 * mirror copies `map_facts` lists come from `map_mirror_sources`, asked only
 * for maps the view says are mirrored. Both reads go once archives move.
 */
final class MapArchives
{
    /**
     * Map name => when its newest listed archive was listed (stored form) and
     * the mirror URLs of that archive, for maps with a listed archive. Every
     * such map when `$names` is null.
     *
     * @param  list<string>|null  $names
     * @return array<string, array{uploaded_at: string, urls: list<string>}>
     */
    public static function listed(?array $names): array
    {
        if ($names === []) {
            return [];
        }
        $out = [];
        foreach ($names === null ? [null] : array_chunk(array_values(array_unique($names)), 100) as $chunk) {
            $rows = ReadAll::rows(function () use ($chunk) {
                $query = Supabase::anon()->remote()->from('map_browse')->select('map_name,uploaded_at,mirror_urls')
                    ->not('uploaded_at', 'is', 'null');
                if ($chunk !== null) {
                    $query->in('map_name', $chunk);
                }

                return $query->order('map_name');
            });
            if ($rows === null) {
                throw new LocalException('Could not read which maps the hub mirrors hold', 503, null);
            }
            foreach ($rows as $row) {
                $out[(string) $row['map_name']] = [
                    'uploaded_at' => Values::stored($row['uploaded_at']),
                    'urls' => array_values(array_map('strval', $row['mirror_urls'] ?? [])),
                ];
            }
        }

        return $out;
    }

    /**
     * Map name => its mirror copies as `map_mirror_sources` gives them, for
     * the named maps that have any.
     *
     * @param  list<string>  $names
     * @return array<string, list<array<string, mixed>>>
     */
    public static function sources(array $names): array
    {
        $mirrored = array_keys(array_filter(self::listed($names), fn (array $held) => $held['urls'] !== []));
        $answers = Supabase::admin()->remote()->rpcEach('map_mirror_sources', array_map(fn (string $name) => ['p_map_name' => $name], $mirrored));
        $out = [];
        foreach ($mirrored as $i => $name) {
            $out[$name] = is_array($answers[$i] ?? null) ? $answers[$i] : [];
        }

        return $out;
    }
}

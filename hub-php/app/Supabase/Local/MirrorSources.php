<?php

namespace App\Supabase\Local;

use Illuminate\Support\Facades\DB;

/**
 * The mirror copies of maps and games, read from MariaDB once the mirrors
 * have moved (#600): `map_mirror_sources`, `game_mirror_sources` and the two
 * mirror columns of `map_browse`, from 20261117120300_hub_mirror_downloads.sql
 * and 20261117150000_map_browse_upload_and_play.sql.
 *
 * A copy counts when its mirror is enabled, healthy and reported holding the
 * newest listed archive. Copies are in mirror id order.
 */
final class MirrorSources
{
    /**
     * Map name => its mirror copies, for every named map. A map with none
     * gets an empty list.
     *
     * @param  list<string>  $names
     * @return array<string, list<array{kind: string, value: string, sha256: string, filename: string}>>
     */
    public static function maps(array $names): array
    {
        $out = array_fill_keys($names, []);
        if ($names === []) {
            return $out;
        }
        $listed = DB::table('archive')->where('kind', 'map')->where('state', 'listed')->whereIn('internal_name', $names)
            ->orderByDesc('listed_at')->orderBy('id')->get(['id', 'internal_name', 'filename', 'sha256']);
        $newest = [];
        foreach ($listed as $archive) {
            $newest[(string) $archive->internal_name] ??= $archive;
        }
        foreach (self::copies(array_values($newest), 'maps') as $name => $copies) {
            $out[$name] = $copies;
        }

        return $out;
    }

    /**
     * Game id => its mirror copies, for every game id given.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{kind: string, value: string, sha256: string, filename: string}>>
     */
    public static function games(array $ids): array
    {
        $out = array_fill_keys($ids, []);
        if ($ids === []) {
            return $out;
        }
        $listed = DB::table('archive')->where('kind', 'game')->where('state', 'listed')->whereIn('game_id', $ids)
            ->orderByDesc('listed_at')->orderBy('id')->get(['id', 'game_id as internal_name', 'filename', 'sha256']);
        $newest = [];
        foreach ($listed as $archive) {
            $newest[(string) $archive->internal_name] ??= $archive;
        }
        foreach (self::copies(array_values($newest), 'games') as $id => $copies) {
            $out[$id] = $copies;
        }

        return $out;
    }

    /**
     * Map name => when its newest listed archive was listed (stored form) and
     * the mirror URLs of that archive, for maps with a listed archive. Every
     * such map when `$names` is null. The same answer {@see MapArchives}
     * reads from Supabase.
     *
     * @param  list<string>|null  $names
     * @return array<string, array{uploaded_at: string, urls: list<string>}>
     */
    public static function listedMaps(?array $names): array
    {
        if ($names === []) {
            return [];
        }
        $query = DB::table('archive')->where('kind', 'map')->where('state', 'listed')
            ->groupBy('internal_name')->selectRaw('internal_name, max(listed_at) as uploaded_at');
        if ($names !== null) {
            $query->whereIn('internal_name', $names);
        }
        $uploaded = $query->pluck('uploaded_at', 'internal_name')->all();
        $out = [];
        foreach (self::maps(array_map('strval', array_keys($uploaded))) as $name => $copies) {
            $out[$name] = [
                'uploaded_at' => Values::stored($uploaded[$name]),
                'urls' => array_column($copies, 'value'),
            ];
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * `url_path_segment`: PHP's `rawurlencode`, which the Postgres function
     * was written to match.
     */
    public static function segment(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * The healthy, enabled mirrors holding each archive, keyed by the
     * archive's `internal_name` field.
     *
     * @param  list<object>  $archives
     * @return array<string, list<array{kind: string, value: string, sha256: string, filename: string}>>
     */
    private static function copies(array $archives, string $folder): array
    {
        if ($archives === []) {
            return [];
        }
        $byId = [];
        foreach ($archives as $archive) {
            $byId[(string) $archive->id] = $archive;
        }
        $held = DB::table('archive_mirror as am')->join('mirror as m', 'm.id', '=', 'am.mirror_id')
            ->whereIn('am.archive_id', array_keys($byId))->where('m.enabled', true)->where('m.healthy', true)
            ->orderBy('m.id')->get(['am.archive_id', 'm.base_url']);
        $out = [];
        foreach ($held as $copy) {
            $archive = $byId[(string) $copy->archive_id];
            // jsonb_build_object's keys, in jsonb's order.
            $out[(string) $archive->internal_name][] = [
                'kind' => 'url',
                'value' => $copy->base_url.'/'.$folder.'/'.self::segment((string) $archive->filename),
                'sha256' => (string) $archive->sha256,
                'filename' => (string) $archive->filename,
            ];
        }

        return $out;
    }
}

<?php

namespace App\Supabase\Local;

use App\Supabase\Supabase;
use Illuminate\Support\Facades\DB;

/**
 * Keeps Supabase's `map` and `map_source_conflict` rows in step with
 * MariaDB's after the maps move (#599).
 *
 * Reports have not moved yet and still read `public.map` inside Postgres:
 * reports name a map by id and the moderation queue embeds it. Until the
 * mirrors move (#600) their archive functions also look a map up by name
 * before taking an upload. Conflicts are kept so `public.clear_map_facts` can
 * delete Supabase's row when a moderator clears a map. Writes happen inside
 * the local transaction, so a refusal from Supabase undoes the local write as
 * well. This ends when reports have moved (#601).
 */
final class MapShadow
{
    /** Columns that change on every submission of a map already held. Nothing in Supabase reads them. */
    private const UNREAD = ['seen_at', 'updated_at'];

    /**
     * Pushes these maps' rows. After an update, `$changed` names the columns
     * set, and a submission that only moved `seen_at` pushes nothing.
     *
     * @param  list<string>  $ids
     * @param  list<string>|null  $changed
     */
    public static function pushMaps(array $ids, ?array $changed = null): void
    {
        if ($ids === [] || ($changed !== null && array_diff($changed, self::UNREAD) === [])) {
            return;
        }
        $rows = self::rows('map', DB::table('map')->whereIn('id', $ids)->get()->all());
        if ($rows !== []) {
            // On the name rather than the id: a row left by a rolled back
            // submission takes the id the map has now.
            Supabase::admin()->remote()->from('map')->upsert($rows, 'map_name')->execute();
        }
    }

    /** @param list<int> $ids */
    public static function pushConflicts(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $rows = self::rows('map_source_conflict', DB::table('map_source_conflict')->whereIn('id', $ids)->get()->all());
        foreach ($rows as &$row) {
            // Supabase numbers its own rows.
            unset($row['id']);
        }
        if ($rows !== []) {
            Supabase::admin()->remote()->from('map_source_conflict')->upsert($rows, 'map_id,reported_source_hash', true)->execute();
        }
    }

    /**
     * Deletes Supabase's row for a map a moderator cleared, with the function
     * Supabase gives the service role for it.
     */
    public static function clear(string $id): void
    {
        Supabase::admin()->remote()->rpc('clear_map_facts', ['p_map_id' => $id]);
    }

    /**
     * @param  list<object>  $stored
     * @return list<array<string, mixed>>
     */
    private static function rows(string $table, array $stored): array
    {
        $types = Tables::COLUMNS[$table];
        $rows = [];
        foreach ($stored as $record) {
            $row = [];
            foreach ($types as $column => $type) {
                $row[$column] = Values::read($type, $record->{$column});
            }
            $rows[] = $row;
        }

        return $rows;
    }
}

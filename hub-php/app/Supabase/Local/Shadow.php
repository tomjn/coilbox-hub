<?php

namespace App\Supabase\Local;

use App\Supabase\Supabase;
use Illuminate\Support\Facades\DB;

/**
 * Keeps Supabase's `game` rows in step with MariaDB's after the move.
 *
 * Areas that have not moved read `public.game` inside Postgres: the hub
 * mirrors' archive functions look up a game's id and owner by shortname,
 * and the staged picture sweep keeps any object a game's logo or banner
 * points at. Every write to a local `game` row is written there too, inside
 * the same transaction, so a refusal from Supabase (a staged path being
 * deleted, say) undoes the local write as well.
 *
 * Once pictures (#598) and mirrors (#600) both answer from MariaDB nothing in
 * Supabase reads these rows, so they are no longer written. Going back to
 * Supabase for either pushes every game first, with {@see pushAll()}.
 */
final class Shadow
{
    /** @param list<string> $ids */
    public static function push(array $ids): void
    {
        if ($ids === [] || ! self::read()) {
            return;
        }
        self::write($ids);
    }

    /**
     * Every local game row, so Supabase's copy is whole again before an area
     * that reads it goes back there. Nothing to do while games are in Supabase.
     */
    public static function pushAll(): void
    {
        if (GameStore::fresh() !== GameStore::MARIADB) {
            return;
        }
        foreach (array_chunk(DB::table(Tables::GAME)->orderBy('id')->pluck('id')->all(), 100) as $ids) {
            self::write(array_map('strval', $ids));
        }
    }

    /** Whether anything in Supabase still reads its game rows. */
    private static function read(): bool
    {
        return PictureStore::fresh() !== PictureStore::MARIADB || MirrorStore::fresh() !== MirrorStore::MARIADB;
    }

    /** @param list<string> $ids */
    private static function write(array $ids): void
    {
        $types = Tables::COLUMNS[Tables::GAME];
        $rows = [];
        foreach (DB::table(Tables::GAME)->whereIn('id', $ids)->get() as $stored) {
            $row = [];
            foreach ($types as $column => $type) {
                $row[$column] = Values::read($type, $stored->{$column});
            }
            $rows[] = $row;
        }
        if ($rows !== []) {
            Supabase::admin()->remote()->from(Tables::GAME)->upsert($rows, 'id')->execute();
        }
    }
}

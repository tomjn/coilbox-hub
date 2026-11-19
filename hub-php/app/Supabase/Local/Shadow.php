<?php

namespace App\Supabase\Local;

use App\Supabase\Supabase;
use Illuminate\Support\Facades\DB;

/**
 * Keeps Supabase's `game` rows in step with MariaDB's after the move.
 *
 * Areas that have not moved yet still read `public.game` inside Postgres: the
 * hub mirrors' archive functions look up a game's id and owner by shortname,
 * and the staged picture sweep keeps any object a game's logo or banner
 * points at. Every write to a local `game` row is written there too, inside
 * the same transaction, so a refusal from Supabase (a staged path being
 * deleted, say) undoes the local write as well. This ends when pictures
 * (#598) and mirrors (#600) have moved.
 */
final class Shadow
{
    /** @param list<string> $ids */
    public static function push(array $ids): void
    {
        if ($ids === []) {
            return;
        }
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

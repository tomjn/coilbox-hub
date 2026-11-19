<?php

namespace App\Supabase\Local;

use Illuminate\Support\Facades\DB;

/**
 * Inserts and updates on the local tables with what Postgres adds around
 * them: defaults, the `updated_at` triggers, check constraints, unique keys
 * (the two partial ones included), foreign keys, the `with check` policies,
 * and Supabase's copy of `game` kept in step for the areas that still read it.
 *
 * Callers run these inside a transaction.
 */
final class Writes
{
    /**
     * A row from a request with its defaults filled, in stored form.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function complete(Caller $caller, string $table, array $row, string $now): array
    {
        $columns = Tables::columns($table);
        foreach (Tables::DEFAULTS[$table] ?? [] as $column => $default) {
            if (! array_key_exists($column, $row)) {
                $row[$column] = $default === 'now' ? $now : $default;
            }
        }
        if ($table === Tables::GAME && ! isset($row['id'])) {
            $row['id'] = Rest::uuid();
        }
        if ($table === 'game_ownership_request' && ! array_key_exists('requested_by_name', $row)) {
            // `public.current_author_name()`, the column's default. Profiles are
            // still in Supabase, so it is asked there as the same caller.
            $row['requested_by_name'] = $caller->client->remote()->rpc('current_author_name');
        }

        return $row;
    }

    /**
     * Inserts one completed row and answers with its id.
     *
     * @param  array<string, mixed>  $row
     */
    public static function insert(Caller $caller, string $table, array $row): int|string
    {
        $stored = self::stored($table, $row);
        self::refuse($table, $stored, null);
        Policies::check($caller, $table, $stored);
        if ($table === 'game_ownership_request' && ($stored['state'] ?? null) === 'open'
            && DB::table($table)->where('game_id', $stored['game_id'])->where('requested_by', $stored['requested_by'])->where('state', 'open')->lockForUpdate()->exists()) {
            throw self::duplicate('game_ownership_request_open_idx', $table);
        }
        if ($table === 'game_download_offer' && ($stored['state'] ?? null) === 'held'
            && DB::table($table)->where('game_id', $stored['game_id'])->where('kind', $stored['kind'])->where('value', $stored['value'])->where('state', 'held')->lockForUpdate()->exists()) {
            throw self::duplicate('game_download_offer_held_idx', $table);
        }

        if (array_key_exists('id', $stored) && $stored['id'] !== null) {
            DB::table($table)->insert($stored);
            $id = $stored['id'];
        } else {
            unset($stored['id']);
            $id = DB::table($table)->insertGetId($stored);
        }
        if ($table === Tables::GAME) {
            Shadow::push([(string) $id]);
        }

        return $id;
    }

    /**
     * Applies one update to rows already chosen and locked, and answers with
     * their ids.
     *
     * @param  list<array<string, mixed>>  $targets  stored rows
     * @param  array<string, mixed>  $values
     * @return list<int|string>
     */
    public static function update(Caller $caller, string $table, array $targets, array $values, string $now): array
    {
        if ($targets === []) {
            return [];
        }
        $columns = Tables::columns($table);
        $changes = self::stored($table, $values);
        if (in_array($table, Tables::TOUCHED, true)) {
            $changes['updated_at'] = $now;
        }
        foreach ($targets as $target) {
            $after = array_merge($target, $changes);
            $decoded = [];
            foreach ($after as $column => $value) {
                $decoded[$column] = isset($columns[$column]) && $columns[$column] === 'json' && is_string($value)
                    ? json_decode($value, true)
                    : $value;
            }
            if (($violation = Tables::violation($table, $decoded)) !== null) {
                throw $violation;
            }
            self::refuse($table, $after, $target['id']);
            Policies::check($caller, $table, $after);
        }
        $ids = array_column($targets, 'id');
        DB::table($table)->whereIn('id', $ids)->update($changes);
        if ($table === Tables::GAME) {
            Shadow::push(array_map('strval', $ids));
        }

        return $ids;
    }

    /**
     * A row converted to stored values, checked against the table's
     * constraints first.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function stored(string $table, array $row): array
    {
        $columns = Tables::columns($table);
        $decoded = [];
        foreach ($row as $column => $value) {
            $decoded[$column] = match (true) {
                is_string($value) && ($columns[$column] ?? null) === 'json' => json_decode($value, true),
                $value instanceof \stdClass || is_array($value) => json_decode(json_encode($value), true),
                default => $value,
            };
        }
        if (($violation = Tables::violation($table, $decoded)) !== null) {
            throw $violation;
        }
        $stored = [];
        foreach ($row as $column => $value) {
            $stored[$column] = Values::write($columns[$column], $value);
        }

        return $stored;
    }

    /**
     * Unique keys and foreign keys, checked before the write so the error
     * carries Postgres's code and wording.
     *
     * @param  array<string, mixed>  $stored
     */
    private static function refuse(string $table, array $stored, int|string|null $self): void
    {
        foreach (Tables::UNIQUE[$table] ?? [] as $name => $key) {
            $query = DB::table($table);
            foreach ($key as $column) {
                if (($stored[$column] ?? null) === null) {
                    continue 2;
                }
                $query->where($column, $stored[$column]);
            }
            if ($self !== null) {
                $query->where('id', '<>', $self);
            }
            if ($query->exists()) {
                throw self::duplicate($name, $table);
            }
        }
        $foreign = Tables::FOREIGN_KEYS[$table] ?? null;
        if ($foreign !== null && ($stored[$foreign[0]] ?? null) !== null
            && ! DB::table($foreign[1])->where($foreign[2], $stored[$foreign[0]])->exists()) {
            throw new LocalException(
                'insert or update on table "'.$table.'" violates foreign key constraint "'.$table.'_'.$foreign[0].'_fkey"', 409, '23503',
            );
        }
    }

    private static function duplicate(string $name, string $table): LocalException
    {
        return new LocalException('duplicate key value violates unique constraint "'.$name.'"', 409, '23505');
    }
}

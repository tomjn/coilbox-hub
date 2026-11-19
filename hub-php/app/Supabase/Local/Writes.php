<?php

namespace App\Supabase\Local;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Inserts and updates on the local tables with what Postgres adds around
 * them: defaults, the `updated_at` triggers, check constraints, unique keys
 * (the two partial ones included), foreign keys, the `with check` policies,
 * the triggers on `asset` ({@see AssetTriggers}), and Supabase's copies of
 * `game` and `map` kept in step for the areas that still read them.
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
        if (in_array($table, Tables::UUID_IDS, true) && ! isset($row['id'])) {
            $row['id'] = Rest::uuid();
        }
        if (in_array($table, ['game_ownership_request', 'map_ownership_request'], true) && ! array_key_exists('requested_by_name', $row)) {
            // `public.current_author_name()`, the column's default. Profiles are
            // still in Supabase, so it is asked there as the same caller.
            $row['requested_by_name'] = $caller->client->remote()->rpc('current_author_name');
        }

        return $row;
    }

    /**
     * Inserts one completed row and answers with its key, as {@see keyOf()} gives it.
     *
     * @param  array<string, mixed>  $row
     * @return int|string|array<string, mixed>
     */
    public static function insert(Caller $caller, string $table, array $row): int|string|array
    {
        $stored = self::stored($table, $row);
        self::refuse($table, $stored, null);
        Policies::check($caller, $table, $stored);
        if ($table === 'game_ownership_request' && ($stored['state'] ?? null) === 'open'
            && DB::table($table)->where('game_id', $stored['game_id'])->where('requested_by', $stored['requested_by'])->where('state', 'open')->lockForUpdate()->exists()) {
            throw self::duplicate('game_ownership_request_open_idx', $table);
        }
        if ($table === 'map_ownership_request' && ($stored['state'] ?? null) === 'open') {
            foreach (['map_id' => 'map_ownership_request_open_map_idx', 'author_key' => 'map_ownership_request_open_author_idx'] as $target => $index) {
                if (($stored[$target] ?? null) !== null && DB::table($table)->where($target, $stored[$target])
                    ->where('requested_by', $stored['requested_by'])->where('state', 'open')->lockForUpdate()->exists()) {
                    throw self::duplicate($index, $table);
                }
            }
        }
        if ($table === 'game_download_offer' && ($stored['state'] ?? null) === 'held'
            && DB::table($table)->where('game_id', $stored['game_id'])->where('kind', $stored['kind'])->where('value', $stored['value'])->where('state', 'held')->lockForUpdate()->exists()) {
            throw self::duplicate('game_download_offer_held_idx', $table);
        }

        if ($table === 'asset') {
            AssetTriggers::before(null, $stored, array_keys($stored));
        }
        if ($table === Tables::GAME) {
            self::refuseReservedGameArt($stored, array_keys($stored));
        }

        if (isset(Tables::PRIMARY_KEYS[$table])) {
            DB::table($table)->insert($stored);
            $id = self::keyOf($table, $stored);
        } elseif (array_key_exists('id', $stored) && $stored['id'] !== null) {
            DB::table($table)->insert($stored);
            $id = $stored['id'];
            if ($table === 'asset') {
                AssetTriggers::after($caller, null, $stored);
            }
        } else {
            unset($stored['id']);
            $id = DB::table($table)->insertGetId($stored);
        }
        if ($table === Tables::GAME) {
            Shadow::push([(string) $id]);
        }
        if ($table === 'map') {
            MapShadow::pushMaps([(string) $id]);
        }
        if ($table === 'map_source_conflict') {
            MapShadow::pushConflicts([(int) $id]);
        }

        return $id;
    }

    /**
     * One row's key: its `id`, or for a table keyed otherwise its key columns.
     *
     * @param  array<string, mixed>  $row
     * @return int|string|array<string, mixed>
     */
    public static function keyOf(string $table, array $row): int|string|array
    {
        if (! isset(Tables::PRIMARY_KEYS[$table])) {
            return $row['id'];
        }
        $key = [];
        foreach (Tables::PRIMARY_KEYS[$table] as $column) {
            $key[$column] = $row[$column];
        }

        return $key;
    }

    /**
     * Narrows a query to rows with these keys, as {@see keyOf()} gives them.
     *
     * @param  list<int|string|array<string, mixed>>  $keys
     */
    public static function whereKeys(Builder $query, string $table, array $keys, string $prefix = ''): Builder
    {
        if (! isset(Tables::PRIMARY_KEYS[$table])) {
            return $query->whereIn($prefix.'id', $keys);
        }

        return $query->where(function (Builder $any) use ($keys, $prefix) {
            $any->whereRaw('1 = 0');
            foreach ($keys as $key) {
                $any->orWhere(function (Builder $one) use ($key, $prefix) {
                    foreach ($key as $column => $value) {
                        $one->where($prefix.$column, $value);
                    }
                });
            }
        });
    }

    /**
     * Applies one update to rows already chosen and locked, and answers with
     * their ids.
     *
     * @param  list<array<string, mixed>>  $targets  stored rows
     * @param  array<string, mixed>  $values
     * @return list<int|string|array<string, mixed>>
     */
    public static function update(Caller $caller, string $table, array $targets, array $values, string $now): array
    {
        if ($targets === []) {
            return [];
        }
        $columns = Tables::columns($table);
        $changes = self::stored($table, $values, false);
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
            self::refuse($table, $after, self::keyOf($table, $target));
            Policies::check($caller, $table, $after);
            if ($table === 'asset') {
                AssetTriggers::before($target, $after, array_keys($values));
            }
            if ($table === Tables::GAME) {
                self::refuseReservedGameArt($after, array_keys($values));
            }
        }
        $ids = array_map(fn (array $target) => self::keyOf($table, $target), $targets);
        self::whereKeys(DB::table($table), $table, $ids)->update($changes);
        if ($table === 'asset') {
            foreach ($targets as $target) {
                AssetTriggers::after($caller, $target, array_merge($target, $changes));
            }
        }
        if ($table === Tables::GAME) {
            Shadow::push(array_map('strval', $ids));
        }
        if ($table === 'map') {
            MapShadow::pushMaps(array_map('strval', $ids), array_keys($changes));
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
    private static function stored(string $table, array $row, bool $whole = true): array
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
        if (($violation = Tables::violation($table, $decoded, $whole)) !== null) {
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
    private static function refuse(string $table, array $stored, int|string|array|null $self): void
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
                $query->whereNot(fn (Builder $same) => self::whereKeys($same, $table, [$self]));
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

    /**
     * `game_refuse_reserved_staged_path`: a game picture may not claim a bucket
     * path while the promotion job has it reserved for deletion. The lock on
     * the reservation row is the one `reserve_staged_deletions` takes. Postgres
     * checked the logo and banner only, so the card is added here.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $set  the columns the statement sets
     */
    private static function refuseReservedGameArt(array $row, array $set): void
    {
        foreach (['logo', 'banner', 'card'] as $kind) {
            if (array_intersect($set, [$kind.'_path', $kind.'_staged_tier']) === [] || ($row[$kind.'_staged_tier'] ?? null) !== 'bucket'
                || ($row[$kind.'_path'] ?? null) === null) {
                continue;
            }
            if (DB::table('staged_object_deletion')->where('path', $row[$kind.'_path'])->lockForUpdate()->exists()) {
                throw new LocalException('staged object '.$row[$kind.'_path'].' is being deleted, so it cannot be claimed until that finishes', 409, '55006');
            }
        }
    }

    private static function duplicate(string $name, string $table): LocalException
    {
        return new LocalException('duplicate key value violates unique constraint "'.$name.'"', 409, '23505');
    }
}

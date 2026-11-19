<?php

namespace App\Supabase\Local;

use App\Supabase\Client;
use App\Supabase\Query;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PostgREST for the game catalogue, the pictures and the maps, answered from MariaDB. It takes the
 * request {@see Query} would have sent and answers as PostgREST
 * would, so the code that reads and writes games did not change when they
 * moved (#597).
 *
 * It covers what the hub sends: column lists with embeds along the foreign
 * keys (`!inner` included, nested), filters on the table and on embeds,
 * `or`, `not`, ordering, limits and offsets on the table and on embeds, exact
 * counts, and insert, update and delete with `return=representation`. Anything
 * else is refused with PostgREST's parse error code rather than guessed at.
 */
final class Rest
{
    /** PostgREST's `max-rows` on Supabase: no response carries more. */
    public const MAX_ROWS = 1000;

    private const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'like', 'ilike', 'is', 'in'];

    private int $aliases = 0;

    private function __construct(private readonly Caller $caller) {}

    /**
     * @param  array<string, string>  $headers
     * @return array{rows: list<array<string, mixed>>, count: int|null}
     */
    public static function handle(Client $client, string $method, string $table, string $query, mixed $body, array $headers): array
    {
        $rest = new self(new Caller($client));

        try {
            $request = Request::parse($query, $headers);
            Tables::columns($table);

            return match ($method) {
                'GET', 'HEAD' => $rest->read($table, $request, $method === 'HEAD'),
                'POST' => $rest->insert($table, $request, $body),
                'PATCH' => $rest->update($table, $request, is_array($body) ? $body : []),
                'DELETE' => $rest->delete($table, $request),
                default => throw new LocalException('Unsupported method '.$method, 405, 'PGRST117'),
            };
        } catch (QueryException $e) {
            throw new LocalException($e->getMessage(), 503, null);
        } catch (LocalException $e) {
            // A request the local store cannot answer is a bug in the hub, not
            // in what a visitor sent, and callers often shrug failures off.
            if (in_array($e->errorCode, ['PGRST100', 'PGRST200', 'PGRST204', '42703'], true)) {
                Log::error('The local game store refused a request: '.$e->getMessage(), ['table' => $table, 'query' => $query]);
            }

            throw $e;
        }
    }

    /** @return array{rows: list<array<string, mixed>>, count: int|null} */
    private function read(string $table, Request $request, bool $head): array
    {
        if ($table === 'game_browse') {
            return GameBrowse::read($this->caller, $this, $request, $head);
        }
        if (in_array($table, Tables::MAP_VIEWS, true)) {
            return MapViews::read($this->caller, $table, $request, $head);
        }
        $select = Select::parse($request->select);
        $alias = $this->alias();
        $query = DB::table($table.' as '.$alias);
        $this->narrow($query, $table, $alias, $select, $request, []);

        $count = null;
        if ($request->count !== null) {
            $count = (clone $query)->count();
        }
        $offset = $request->offset([]);
        if ($count !== null && $offset > $count) {
            throw new LocalException('Requested range not satisfiable', 416, 'PGRST103', 'An offset of '.$offset.' was requested, but there are only '.$count.' rows.');
        }
        if ($head) {
            return ['rows' => [], 'count' => $count];
        }

        $this->orderBy($query, $table, $alias, $request->order([]));
        $limit = min($request->limit([]) ?? self::MAX_ROWS, self::MAX_ROWS);
        $query->offset($offset)->limit($limit);
        $stored = array_map(fn ($row) => (array) $row, $query->select($alias.'.*')->get()->all());

        return ['rows' => $this->shape($table, $select, $stored, $request, []), 'count' => $count];
    }

    /**
     * Rows of a table, stored values, shaped as the select list asks with
     * every embed fetched. Used for the top level and for every embed.
     *
     * @param  list<array<string, mixed>>  $stored
     * @param  list<string>  $path
     * @return list<array<string, mixed>>
     */
    public function shape(string $table, Select $select, array $stored, Request $request, array $path): array
    {
        $embedded = [];
        foreach ($select->embeds() as $embed) {
            $embedded[$embed->key()] = $this->embed($table, $embed, $stored, $request, [...$path, $embed->key()]);
        }
        $columns = Tables::columns($table);
        // Columns first in select order, then the embeds in PostgREST's order.
        // An embedded row is jsonb, so its keys come in jsonb's order.
        $embedOrder = $request->embedOrder(array_keys($embedded), $path);
        $out = [];
        foreach ($stored as $index => $row) {
            $shaped = [];
            foreach ($select->items as $item) {
                if ($item->kind === 'star') {
                    foreach ($columns as $column => $type) {
                        $shaped[$column] = Values::read($type, $row[$column] ?? null);
                    }
                } elseif ($item->kind === 'column') {
                    if (! isset($columns[$item->name])) {
                        throw new LocalException('column '.$table.'.'.$item->name.' does not exist', 400, '42703');
                    }
                    $value = Values::read($columns[$item->name], $row[$item->name] ?? null);
                    $shaped[$item->alias ?? $item->name] = $item->path === [] ? $value : $item->at($value);
                }
            }
            foreach ($embedOrder as $key) {
                $shaped[$key] = $embedded[$key][$index];
            }
            $out[] = $path === [] ? $shaped : Values::jsonbKeys($shaped);
        }

        return $out;
    }

    /**
     * One embed for every parent row, in parent order: a row or null along a
     * foreign key, a list against one.
     *
     * @param  list<array<string, mixed>>  $parents
     * @param  list<string>  $path
     * @return list<mixed>
     */
    private function embed(string $parentTable, SelectItem $embed, array $parents, Request $request, array $path): array
    {
        [$childTable, $childColumn, $parentColumn, $many] = self::relation($parentTable, $embed->name);
        $keys = array_values(array_unique(array_filter(array_column($parents, $parentColumn), fn ($key) => $key !== null)));
        $rows = [];
        if ($keys !== []) {
            $alias = $this->alias();
            $query = DB::table($childTable.' as '.$alias)->whereIn($alias.'.'.$childColumn, $keys);
            $this->narrow($query, $childTable, $alias, $embed->nested, $request, $path);
            $this->orderBy($query, $childTable, $alias, $request->order($path));
            $rows = array_map(fn ($row) => (array) $row, $query->select($alias.'.*')->get()->all());
        }
        $shaped = $this->shape($childTable, $embed->nested, $rows, $request, $path);

        $grouped = [];
        foreach ($rows as $index => $row) {
            $grouped[(string) $row[$childColumn]][] = $shaped[$index];
        }
        $limit = $request->limit($path);
        $offset = $request->offset($path);

        return array_map(function (array $parent) use ($grouped, $parentColumn, $many, $limit, $offset) {
            $found = $grouped[(string) ($parent[$parentColumn] ?? '')] ?? [];
            if (! $many) {
                return $found[0] ?? null;
            }

            return array_slice($found, $offset, $limit);
        }, $parents);
    }

    /**
     * Row level security, the filters at this level and the `!inner` embeds
     * below it, which keep a row only when they find something.
     *
     * @param  list<string>  $path
     */
    private function narrow(Builder $query, string $table, string $alias, Select $select, Request $request, array $path): void
    {
        Policies::readGrant($this->caller, $table);
        Policies::read($this->caller, $query, $table, $alias);
        foreach ($request->filters($path) as $filter) {
            Filter::apply($query, $table, $alias, $filter);
        }
        foreach ($select->embeds() as $embed) {
            $embedPath = [...$path, $embed->key()];
            if (! $embed->inner) {
                // Filters on a plain embed narrow the embed, not its parent.
                continue;
            }
            [$childTable, $childColumn, $parentColumn] = self::relation($table, $embed->name);
            $query->whereExists(function (Builder $exists) use ($childTable, $childColumn, $parentColumn, $alias, $embed, $request, $embedPath) {
                $childAlias = $this->alias();
                $exists->from($childTable.' as '.$childAlias)->whereColumn($childAlias.'.'.$childColumn, $alias.'.'.$parentColumn);
                $this->narrow($exists, $childTable, $childAlias, $embed->nested, $request, $embedPath);
            });
        }
    }

    /** @param list<array{0: string, 1: bool, 2: ?bool}> $order */
    public function orderBy(Builder $query, string $table, string $alias, array $order): void
    {
        $columns = Tables::columns($table);
        foreach ($order as [$column, $ascending, $nullsFirst]) {
            if (! isset($columns[$column])) {
                throw new LocalException('column '.$table.'.'.$column.' does not exist', 400, '42703');
            }
            $qualified = $alias.'.'.$column;
            // Postgres puts nulls last going up and first going down.
            $nullsFirst ??= ! $ascending;
            $query->orderByRaw($query->getGrammar()->wrap($qualified).' is null '.($nullsFirst ? 'desc' : 'asc'));
            $direction = $ascending ? 'asc' : 'desc';
            if ($columns[$column] === 'text') {
                $query->orderByRaw(self::localeOrder($query->getGrammar()->wrap($qualified)).' '.$direction);
            }
            $query->orderBy($qualified, $direction);
        }
        if ($order === []) {
            // Postgres answers an unordered read in storage order, which is
            // close to the order rows arrived.
            if ($table === Tables::GAME || $table === 'asset' || $table === 'map') {
                $query->orderBy($alias.'.created_at');
            }
            foreach (Tables::primaryKey($table) as $column) {
                $query->orderBy($alias.'.'.$column);
            }
        }
    }

    /**
     * Supabase's databases sort text by the `en_US.UTF-8` locale, where case
     * comes second: `BYAR`, `ca`, `EE`. A Unicode collation is the nearest
     * MariaDB has, and SQLite's NOCASE stands in for it in tests.
     */
    private static function localeOrder(string $wrapped): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? $wrapped.' collate nocase'
            : $wrapped.' collate utf8mb4_unicode_520_ci';
    }

    /**
     * How an embed joins: [child table, child column, parent column, list].
     *
     * @return array{0: string, 1: string, 2: string, 3: bool}
     */
    private static function relation(string $table, string $embed): array
    {
        $up = Tables::FOREIGN_KEYS[$table] ?? null;
        if ($up !== null && $up[1] === $embed) {
            return [$embed, $up[2], $up[0], false];
        }
        $down = Tables::FOREIGN_KEYS[$embed] ?? null;
        if ($down !== null && $down[1] === $table) {
            return [$embed, $down[0], $down[2], true];
        }

        throw new LocalException(
            "Could not find a relationship between '{$table}' and '{$embed}' in the schema cache", 400, 'PGRST200',
        );
    }

    /** @return array{rows: list<array<string, mixed>>, count: int|null} */
    private function insert(string $table, Request $request, mixed $body): array
    {
        if ($request->upsert && $request->onConflict === []) {
            throw new LocalException('Only an upsert on named columns is supported by the local store', 400, 'PGRST100');
        }
        // `on conflict do update` needs the update privilege as well, even
        // when every row turns out to be new.
        $merges = $request->upsert && ! $request->ignoreDuplicates;
        $rows = is_array($body) && array_is_list($body) ? $body : [$body];
        $columns = Tables::columns($table);
        $named = [];
        foreach ($rows as $row) {
            $named = array_values(array_unique([...$named, ...array_keys((array) $row)]));
        }
        Policies::grant($this->caller, $table, 'insert', $named);
        if ($merges) {
            Policies::grant($this->caller, $table, 'update', $named);
        }

        $now = Values::now();
        $ids = DB::transaction(function () use ($table, $rows, $columns, $now, $request, $merges) {
            $ids = [];
            foreach ($rows as $row) {
                $row = (array) $row;
                foreach (array_keys($row) as $column) {
                    if (! isset($columns[$column])) {
                        throw new LocalException("Could not find the '{$column}' column of '{$table}' in the schema cache", 400, 'PGRST204');
                    }
                }
                if ($request->upsert && ($held = $this->duplicate($table, $row, $request->onConflict)) !== null) {
                    if ($merges) {
                        array_push($ids, ...Writes::update($this->caller, $table, [$held], $row, $now));
                    }

                    continue;
                }
                $record = Writes::complete($this->caller, $table, $row, $now);
                $ids[] = Writes::insert($this->caller, $table, $record);
            }

            return $ids;
        });

        return ['rows' => $request->returns ? $this->byIds($table, $request, $ids) : [], 'count' => null];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{rows: list<array<string, mixed>>, count: int|null}
     */
    private function update(string $table, Request $request, array $values): array
    {
        $columns = Tables::columns($table);
        foreach (array_keys($values) as $column) {
            if (! isset($columns[$column])) {
                throw new LocalException("Could not find the '{$column}' column of '{$table}' in the schema cache", 400, 'PGRST204');
            }
        }
        Policies::grant($this->caller, $table, 'update', array_keys($values));

        $ids = DB::transaction(function () use ($table, $request, $values) {
            if ($values === []) {
                return [];
            }
            $alias = $this->alias();
            $query = DB::table($table.' as '.$alias);
            Policies::using($this->caller, $query, $table, 'update', $alias);
            foreach ($request->filters([]) as $filter) {
                Filter::apply($query, $table, $alias, $filter);
            }
            $targets = array_map(fn ($row) => (array) $row, $query->select($alias.'.*')->lockForUpdate()->get()->all());

            return Writes::update($this->caller, $table, $targets, $values, Values::now());
        });

        return ['rows' => $request->returns ? $this->byIds($table, $request, $ids) : [], 'count' => null];
    }

    /**
     * The stored row that already holds these values in the `on_conflict`
     * columns, for `on conflict do nothing` and `do update`, or null.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     * @return array<string, mixed>|null
     */
    private function duplicate(string $table, array $row, array $columns): ?array
    {
        $types = Tables::columns($table);
        $query = DB::table($table);
        foreach ($columns as $column) {
            if (! isset($types[$column])) {
                throw new LocalException('column "'.$column.'" does not exist', 400, '42703');
            }
            $value = $row[$column] ?? null;
            if ($value === null) {
                // A null never conflicts.
                return null;
            }
            $query->where($column, Values::write($types[$column], $value));
        }
        $held = $query->lockForUpdate()->first();

        return $held === null ? null : (array) $held;
    }

    /** @return array{rows: list<array<string, mixed>>, count: int|null} */
    private function delete(string $table, Request $request): array
    {
        if ($this->caller->isService() && ! Tables::isMap($table) && ! in_array($table, ['game_faction', 'game_download_source'], true)) {
            // The service role holds delete on these two catalogue tables only.
            // Its grants on the map tables are in Policies.
            throw new LocalException('permission denied for table '.$table, 403, '42501');
        }
        Policies::grant($this->caller, $table, 'delete', []);
        $removed = DB::transaction(function () use ($table, $request) {
            $alias = $this->alias();
            $query = DB::table($table.' as '.$alias);
            Policies::using($this->caller, $query, $table, 'delete', $alias);
            foreach ($request->filters([]) as $filter) {
                Filter::apply($query, $table, $alias, $filter);
            }
            $rows = array_map(fn ($row) => (array) $row, $query->select($alias.'.*')->lockForUpdate()->get()->all());
            $ids = array_map(fn (array $row) => Writes::keyOf($table, $row), $rows);
            if ($ids !== []) {
                Writes::whereKeys(DB::table($table), $table, $ids)->delete();
            }

            return $rows;
        });

        $shaped = $request->returns ? $this->shape($table, Select::parse($request->select), $removed, $request, []) : [];

        return ['rows' => $shaped, 'count' => null];
    }

    /**
     * Written rows read back for `return=representation`.
     *
     * @param  list<int|string|array<string, mixed>>  $ids
     * @return list<array<string, mixed>>
     */
    private function byIds(string $table, Request $request, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $select = Select::parse($request->select);
        $alias = $this->alias();
        $query = Writes::whereKeys(DB::table($table.' as '.$alias), $table, $ids, $alias.'.');
        Policies::read($this->caller, $query, $table, $alias);
        $stored = array_map(fn ($row) => (array) $row, $query->select($alias.'.*')->get()->all());
        $name = fn ($id) => is_array($id) ? json_encode(array_map('strval', $id)) : (string) $id;
        $order = array_flip(array_map($name, $ids));
        $place = fn (array $row) => $order[$name(Writes::keyOf($table, $row))] ?? 0;
        usort($stored, fn ($a, $b) => $place($a) <=> $place($b));

        return $this->shape($table, $select, $stored, $request, []);
    }

    private function alias(): string
    {
        return 't'.$this->aliases++;
    }

    /** A new uuid, as `gen_random_uuid()`. */
    public static function uuid(): string
    {
        return (string) Str::uuid();
    }
}

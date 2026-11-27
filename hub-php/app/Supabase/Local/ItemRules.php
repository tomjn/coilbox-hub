<?php

namespace App\Supabase\Local;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What Postgres does around `item` and `report` besides grants and row level
 * security (#601), from production's schema on 19 November 2026: the check
 * constraints on the container, the generated `mode` and `search` columns,
 * `clone_count`, the publish rate limit, the report dedupe trigger and the
 * `item_facet` view.
 */
final class ItemRules
{
    /** `enforce_publish_rate_limit`: items one account may publish in an hour. */
    public const PUBLISH_PER_HOUR = 20;

    /** `item_container_check`: the container's jsonb text, in bytes. */
    public const MAX_CONTAINER_BYTES = 524288;

    /** Postgres's `E' \t\n\r\f\v'`, what `clone_count` trims a key of. */
    private const SPACE = " \t\n\r\f\v";

    /**
     * The check a whole row breaks, by name, or null. The container's checks
     * are in {@see containerViolation()}, run on insert where the container
     * still holds its objects.
     *
     * @param  array<string, mixed>  $row
     */
    public static function violation(string $table, array $row): ?string
    {
        $v = fn (string $column) => $row[$column] ?? null;
        if ($table === 'item') {
            return $v('kind_version') !== null && (int) $v('kind_version') <= 0 ? 'item_kind_version_check' : null;
        }
        if ($table === 'report') {
            if (array_key_exists('map_id', $row) && ($v('kind') !== null) !== ($v('map_id') !== null)) {
                return 'report_kind_matches_target_check';
            }
            $targets = ['item_id', 'map_id', 'replay_id'];
            if (array_intersect($targets, array_keys($row)) !== [] && count(array_filter($targets, fn ($t) => $v($t) !== null)) !== 1) {
                return 'report_one_target_check';
            }
        }

        return null;
    }

    /**
     * The container checks: its size, that it is a Coilbox frame, and that a
     * module holds no game geometry. A check whose expression is null passes,
     * as in Postgres.
     */
    public static function containerViolation(string $kind, mixed $container): ?string
    {
        if (strlen(self::jsonbText($container)) > self::MAX_CONTAINER_BYTES) {
            return 'item_container_check';
        }
        if (self::frame($container) === false) {
            return 'item_container_is_frame';
        }
        if (self::moduleGeometry($kind, $container) === false) {
            return 'item_module_has_no_game_geometry';
        }

        return null;
    }

    /** `container -> 'payload' ->> 'mode'`, the generated `mode` column. */
    public static function mode(mixed $container): ?string
    {
        $container = self::objects($container);
        $payload = $container instanceof stdClass ? ($container->payload ?? null) : null;
        if (! $payload instanceof stdClass || ! property_exists($payload, 'mode')) {
            return null;
        }

        return self::text($payload->mode);
    }

    /**
     * `enforce_publish_rate_limit`: refuses a publish once the account has
     * published {@see PUBLISH_PER_HOUR} in the last hour.
     */
    public static function refuseTooMany(string $authorId): void
    {
        $since = now('UTC')->subHour()->format('Y-m-d H:i:s.u');
        $recent = DB::table('item')->where('author_id', $authorId)->where('created_at', '>', $since)->count();
        if ($recent >= self::PUBLISH_PER_HOUR) {
            throw new LocalException('Too many published in the last hour. Try again later.', 400, '53400');
        }
    }

    /**
     * `dedupe_open_report`: whether an open report on the same item, map or replay is
     * already held, in which case the new one is dropped without an error.
     *
     * @param  array<string, mixed>  $row
     */
    public static function openReportHeld(array $row): bool
    {
        $targets = array_filter(['item_id' => $row['item_id'] ?? null, 'map_id' => $row['map_id'] ?? null, 'replay_id' => $row['replay_id'] ?? null], fn ($id) => $id !== null);
        if ($targets === []) {
            return false;
        }

        return DB::table('report')->whereNull('handled_at')->where(function (Builder $same) use ($targets) {
            foreach ($targets as $column => $id) {
                $same->orWhere($column, (string) $id);
            }
        })->exists();
    }

    /**
     * What Postgres's foreign keys to `auth.users` did when an account was
     * deleted: its items, reports on them, its profile and capabilities go,
     * and it is taken off the rows it only touched. They cannot reach MariaDB.
     */
    public static function forgetAccount(string $userId): void
    {
        DB::transaction(function () use ($userId) {
            $items = DB::table('item')->where('author_id', $userId)->pluck('id')->all();
            if ($items !== []) {
                DB::table('report')->whereIn('item_id', $items)->delete();
                DB::table('item')->whereIn('id', $items)->delete();
            }
            DB::table('profile')->where('user_id', $userId)->delete();
            DB::table('user_capability')->where('user_id', $userId)->delete();
            DB::table('user_capability')->where('granted_by', $userId)->update(['granted_by' => null]);
            DB::table('report')->where('reporter_id', $userId)->update(['reporter_id' => null]);
            DB::table('report')->where('handled_by', $userId)->update(['handled_by' => null]);
            // `item_touch_updated_at` runs on the update that sets it null.
            DB::table('item')->where('featured_by', $userId)->update(['featured_by' => null, 'updated_at' => Values::now()]);
        });
    }

    /** A column worked out from a stored row, as {@see Tables::COMPUTED} lists them. @param array<string, mixed> $row */
    public static function computed(string $table, string $column, array $row): mixed
    {
        if ($table === 'item' && $column === 'clone_count') {
            return self::cloneCount(json_decode((string) ($row['container'] ?? 'null'), false));
        }

        throw new LocalException('column '.$table.'.'.$column.' does not exist', 400, '42703');
    }

    /**
     * `public.clone_count(item)`: the distinct keys of the project's clone
     * edits, trimmed and lower cased, that name an object.
     */
    public static function cloneCount(mixed $container): int
    {
        $clones = $container instanceof stdClass ? ($container->payload->edits->clones ?? null) : null;
        if (! $clones instanceof stdClass) {
            return 0;
        }
        $keys = [];
        foreach (get_object_vars($clones) as $key => $value) {
            $trimmed = mb_strtolower(trim((string) $key, self::SPACE));
            if ($value instanceof stdClass && $trimmed !== '') {
                $keys[$trimmed] = true;
            }
        }

        return count($keys);
    }

    /**
     * A filter on `search`, the tsvector of the title (weight A) and the
     * description (weight B). Only `wfts`, `websearch_to_tsquery`, is sent.
     */
    public static function search(Builder $where, string $alias, string $operator, string $query): void
    {
        if ($operator !== 'wfts') {
            throw new LocalException('The local store only searches items with wfts', 400, 'PGRST100');
        }
        $ids = [];
        foreach (DB::table('item')->select(['id', 'title', 'description'])->get() as $item) {
            if (TextSearch::matches(TextSearch::vector([$item->title, $item->description]), $query)) {
                $ids[] = $item->id;
            }
        }
        $ids === [] ? $where->whereRaw('1 = 0') : $where->whereIn($alias.'.id', $ids);
    }

    /**
     * The `item_facet` view: each game key and each map name some live item
     * names, for the gallery's filter chips. Ordering, limits and counts as
     * PostgREST gives them. Filters are refused, as nothing sends one.
     *
     * @return array{rows: list<array<string, mixed>>, count: int|null}
     */
    public static function facets(Caller $caller, Rest $rest, Request $request, bool $head): array
    {
        Policies::readGrant($caller, 'item_facet');
        if ($request->filters([]) !== []) {
            throw new LocalException('The local store does not filter item_facet', 400, 'PGRST100');
        }
        $rows = [];
        foreach (['game' => 'game_key', 'map' => 'map_name'] as $facet => $column) {
            $values = DB::table('item')->whereNull('deleted_at')->whereNotNull($column)->where($column, '<>', '')
                ->distinct()->pluck($column)->all();
            foreach ($values as $value) {
                $rows[] = ['facet' => $facet, 'value' => (string) $value];
            }
        }
        foreach (array_reverse($request->order([])) as [$column, $ascending]) {
            if (! in_array($column, ['facet', 'value'], true)) {
                throw new LocalException('column item_facet.'.$column.' does not exist', 400, '42703');
            }
            usort($rows, fn (array $a, array $b) => ($ascending ? 1 : -1) * MapViews::compareText($a[$column], $b[$column]));
        }
        $count = $request->count !== null ? count($rows) : null;
        if ($head) {
            return ['rows' => [], 'count' => $count];
        }
        $rows = array_slice($rows, $request->offset([]), min($request->limit([]) ?? Rest::MAX_ROWS, Rest::MAX_ROWS));

        return ['rows' => $rest->shape('item_facet', Select::parse($request->select), $rows, $request, []), 'count' => $count];
    }

    /**
     * jsonb's text form, as `::text` gives it: keys in jsonb's order, a space
     * after each comma and colon. Objects must be {@see stdClass} so `{}`
     * stays an object.
     */
    public static function jsonbText(mixed $value): string
    {
        if ($value instanceof stdClass) {
            $parts = [];
            foreach (Values::jsonbKeys(get_object_vars($value)) as $key => $item) {
                $parts[] = self::scalar((string) $key).': '.self::jsonbText($item);
            }

            return '{'.implode(', ', $parts).'}';
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                return self::jsonbText(self::objects($value));
            }

            return '['.implode(', ', array_map(self::jsonbText(...), $value)).']';
        }

        return self::scalar($value);
    }

    private static function scalar(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** `->>`: a string as itself, anything else as its jsonb text, JSON null as null. */
    private static function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            default => self::jsonbText($value),
        };
    }

    /** A value with its objects as {@see stdClass}, the way PostgREST would have received it. */
    private static function objects(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, false);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), false);
    }

    /** `jsonb_typeof`, or null for a missing value. */
    private static function type(mixed $value, bool $present = true): ?string
    {
        if (! $present) {
            return null;
        }

        return match (true) {
            $value instanceof stdClass => 'object',
            is_array($value) => 'array',
            is_string($value) => 'string',
            is_int($value), is_float($value) => 'number',
            is_bool($value) => 'boolean',
            default => 'null',
        };
    }

    /** `item_container_is_frame`, true, false or null as SQL answers it. */
    private static function frame(mixed $container): ?bool
    {
        $container = self::objects($container);
        if (! $container instanceof stdClass) {
            return false;
        }
        $has = fn (string $key) => property_exists($container, $key);
        $format = $has('format') ? self::text($container->format) : null;
        $parts = [
            $format === null ? null : $format === 'coilbox',
            self::equals(self::type($container->container ?? null, $has('container')), 'number'),
            self::equals(self::type($container->kind ?? null, $has('kind')), 'string'),
            self::equals(self::type($container->kindVersion ?? null, $has('kindVersion')), 'number'),
            $has('payload'),
        ];

        return self::all($parts);
    }

    /** `item_module_has_no_game_geometry`, true, false or null as SQL answers it. */
    private static function moduleGeometry(string $kind, mixed $container): ?bool
    {
        $container = self::objects($container);
        $innerKind = $container instanceof stdClass && property_exists($container, 'kind') ? self::text($container->kind) : null;
        $notModule = self::all([$kind !== 'module', $innerKind === null ? null : $innerKind !== 'module']);
        if ($notModule === true) {
            return true;
        }
        $payload = $container instanceof stdClass ? ($container->payload ?? null) : null;
        $pieces = $payload instanceof stdClass ? ($payload->pieces ?? null) : null;
        $geometry = $payload instanceof stdClass && property_exists($payload, 'geometry') ? $payload->geometry : null;
        $hasGeometry = $payload instanceof stdClass && property_exists($payload, 'geometry');

        $clean = is_array($pieces);
        if ($clean) {
            $meshed = false;
            foreach ($pieces as $piece) {
                if (! $piece instanceof stdClass) {
                    continue;
                }
                if (($piece->lifted ?? null) instanceof stdClass) {
                    $clean = false;
                }
                if (is_string($piece->meshId ?? null)) {
                    $meshed = true;
                    $own = $piece->ownMesh ?? null;
                    if (! ($own instanceof stdClass && is_string($own->file ?? null) && $own->file !== '')) {
                        $clean = false;
                    }
                }
            }
            if ($meshed && ! ($hasGeometry && is_string($geometry))) {
                $clean = false;
            }
        }

        return $clean ? true : ($notModule === null ? null : false);
    }

    private static function equals(?string $type, string $wanted): ?bool
    {
        return $type === null ? null : $type === $wanted;
    }

    /** SQL's `and` over true, false and null. @param list<?bool> $parts */
    private static function all(array $parts): ?bool
    {
        if (in_array(false, $parts, true)) {
            return false;
        }

        return in_array(null, $parts, true) ? null : true;
    }
}

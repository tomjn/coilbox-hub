<?php

namespace App\Supabase\Local;

use App\Domain\Maps\Authors;
use App\Supabase\Client;
use App\Supabase\SupabaseException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The map functions the hub calls, done in PHP on the local tables (#599), as
 * production's schema defines them on 19 November 2026. Each keeps its
 * grants and its answers.
 *
 * Archives and mirrors stay in Supabase until #600, so `map_facts` reads
 * the mirror copies there through {@see MapArchives}, and
 * `map_mirror_status`, which is all archive data, stays a Supabase function
 * until then. Its `can_edit_map` check reads Supabase's `map` rows, which
 * {@see MapShadow} keeps in step. `is_moderator` stays in Supabase too.
 */
final class MapFunctions
{
    /** Function => roles granted execute. */
    private const GRANTS = [
        'map_facts' => ['service_role'],
        'submit_map_facts' => ['service_role'],
        'clear_map_facts' => ['service_role'],
        'author_key' => ['anon', 'authenticated', 'service_role'],
        'resolved_author_key' => ['anon', 'authenticated', 'service_role'],
        'merge_author_keys' => ['service_role'],
        'can_edit_map' => ['authenticated', 'service_role'],
        'add_maps_to_pack' => ['service_role'],
    ];

    private const READS = ['map_facts', 'author_key', 'resolved_author_key', 'can_edit_map'];

    /** `enforce_map_submission_rate_limit`: maps one account may add in an hour. */
    private const MAPS_PER_HOUR = 5000;

    public static function handles(string $function): bool
    {
        return isset(self::GRANTS[$function]);
    }

    public static function writes(string $function): bool
    {
        return ! in_array($function, self::READS, true);
    }

    /** @param array<string, mixed> $args */
    public static function call(Client $client, string $function, array $args): mixed
    {
        $caller = new Caller($client);
        if (! in_array($caller->role(), self::GRANTS[$function], true)) {
            throw new LocalException('permission denied for function '.$function, 401, '42501');
        }

        try {
            return match ($function) {
                'map_facts' => self::facts(self::texts($args['p_names'] ?? [])),
                'submit_map_facts' => self::submit(is_array($args['p_maps'] ?? null) ? $args['p_maps'] : [], $args['p_submitted_by'] ?? null),
                'clear_map_facts' => self::clear((string) ($args['p_map_id'] ?? '')),
                'author_key' => Authors::key(isset($args['credit']) ? (string) $args['credit'] : null),
                'resolved_author_key' => isset($args['credit_key'])
                    ? Authors::resolve((string) $args['credit_key'], MapViews::aliases()) : null,
                'merge_author_keys' => self::merge($args['p_into'] ?? null, self::texts($args['p_keys'] ?? []), $args['p_name'] ?? null, $args['p_note'] ?? null),
                'can_edit_map' => self::canEditMap($caller, (string) ($args['p_map_id'] ?? '')),
                'add_maps_to_pack' => self::addToPack((string) ($args['p_pack_id'] ?? ''), self::texts($args['p_names'] ?? [])),
            };
        } catch (QueryException $e) {
            throw new LocalException($e->getMessage(), 503, null);
        }
    }

    /**
     * `can_edit_map`: a signed in caller who moderates, owns the map, or owns
     * one of the authors it credits.
     */
    public static function canEditMap(Caller $caller, string $mapId): bool
    {
        $editable = self::editableMapIds($caller);

        return $editable === null || in_array($mapId, $editable, true);
    }

    /**
     * The maps a caller may edit, or null for every map.
     *
     * @return list<string>|null
     */
    public static function editableMapIds(Caller $caller): ?array
    {
        $userId = $caller->userId();
        if ($userId === null) {
            return [];
        }
        if ($caller->isModerator()) {
            return null;
        }
        $ids = DB::table('map')->where('owner_user_id', $userId)->pluck('id')->all();
        $owned = DB::table('author_owner')->where('user_id', $userId)->pluck('key')->all();
        if ($owned !== []) {
            $aliases = MapViews::aliases();
            $keys = array_flip(array_map(fn ($key) => Authors::resolve((string) $key, $aliases), $owned));
            foreach (DB::table('map_author')->select('map_id', 'key')->get() as $author) {
                if (isset($keys[Authors::resolve((string) $author->key, $aliases)])) {
                    $ids[] = $author->map_id;
                }
            }
        }

        return array_values(array_unique(array_map('strval', $ids)));
    }

    /**
     * `map_facts`: what the hub holds about each named map, as one JSON
     * array. A name the hub does not hold is left out.
     *
     * @param  list<string>  $names
     * @return list<array<string, mixed>>
     */
    private static function facts(array $names): array
    {
        $maps = $names === [] ? [] : array_map(fn ($row) => (array) $row, DB::table('map')->whereIn('map_name', $names)->get()->all());
        if ($maps === []) {
            return [];
        }
        $ids = array_column($maps, 'id');
        $aliases = MapViews::aliases();
        $shown = MapViews::displayNames();

        $credits = [];
        foreach (DB::table('map_author')->whereIn('map_id', $ids)->orderBy('credit_index')->get() as $author) {
            $credits[$author->map_id][Authors::resolve((string) $author->key, $aliases)] ??= (int) $author->credit_index;
        }
        $points = [];
        foreach (DB::table('map_point')->whereIn('map_id', $ids)->orderBy('ordinal')->get() as $point) {
            $points[$point->map_id][$point->kind][] = [
                'x' => Values::read('float', $point->x), 'z' => Values::read('float', $point->z),
                'y' => Values::read('float', $point->y), 'meta' => Values::read('json', $point->meta),
            ];
        }
        $sources = [];
        foreach (DB::table('map_download_source')->whereIn('map_id', $ids)->orderBy('sort_order')->orderBy('id')->get() as $source) {
            $sources[$source->map_id][] = array_filter(
                ['kind' => $source->kind, 'value' => $source->value, 'asset' => $source->asset, 'filename' => $source->filename],
                fn ($value) => $value !== null,
            );
        }
        $mirrors = MapArchives::sources(array_column($maps, 'map_name'));

        $out = [];
        foreach ($maps as $map) {
            $order = $credits[$map['id']] ?? [];
            asort($order);
            $real = fn (string $column) => Values::read('float', $map[$column]);
            $out[] = [
                'map_name' => $map['map_name'],
                'facts' => [
                    'slug' => $map['slug'],
                    'display_name' => $map['display_name'],
                    'description' => $map['description'],
                    'authors' => array_map(fn ($key) => ['key' => (string) $key, 'name' => $shown[$key] ?? null], array_keys($order)),
                    'width_elmos' => (int) $map['width_elmos'],
                    'height_elmos' => (int) $map['height_elmos'],
                    'world_height_min' => $real('world_height_min'),
                    'world_height_max' => $real('world_height_max'),
                    'min_wind' => $real('min_wind'),
                    'max_wind' => $real('max_wind'),
                    'tidal_strength' => $real('tidal_strength'),
                    'void_water' => Values::read('bool', $map['void_water']),
                    'water_coverage' => $real('water_coverage'),
                    'tags' => MapViews::tags($map),
                    'points' => array_merge(['start' => [], 'metal' => [], 'geo' => []], $points[$map['id']] ?? []),
                    'appearance' => json_decode((string) $map['appearance'], false),
                    'downloads' => [...($sources[$map['id']] ?? []), ...($mirrors[$map['map_name']] ?? [])],
                ],
            ];
        }

        // Through jsonb's key order and back, as PostgREST would answer.
        return json_decode(Values::json($out), true);
    }

    /**
     * `submit_map_facts`: each map stored, replaced, left unchanged or
     * refused, entry by entry. One entry's failure is that entry's alone,
     * except the rate limit, which refuses the whole batch.
     *
     * @param  list<mixed>  $submissions
     * @return list<array{map_name: mixed, outcome: string|null, said: string|null}>
     */
    private static function submit(array $submissions, mixed $submittedBy): array
    {
        $submittedBy = is_string($submittedBy) ? $submittedBy : null;

        return DB::transaction(function () use ($submissions, $submittedBy) {
            $results = [];
            foreach ($submissions as $submission) {
                $entry = is_array($submission['entry'] ?? null) ? $submission['entry'] : [];
                $name = $entry['map_name'] ?? null;
                $outcome = null;
                $said = null;
                try {
                    $outcome = DB::transaction(fn () => self::submitOne($submission, $entry, $submittedBy));
                } catch (LocalException $e) {
                    if ($e->errorCode === '53400') {
                        throw $e;
                    }
                    $outcome = 'refused';
                    $said = $e->getMessage();
                } catch (QueryException|SupabaseException $e) {
                    $outcome = 'refused';
                    $said = $e->getMessage();
                }
                $results[] = ['map_name' => $name, 'outcome' => $outcome, 'said' => $said];
            }

            return $results;
        });
    }

    /**
     * One entry, inside its own savepoint.
     *
     * @param  array<string, mixed>  $submission
     * @param  array<string, mixed>  $entry
     */
    private static function submitOne(array $submission, array $entry, ?string $submittedBy): string
    {
        $now = Values::now();
        $name = (string) ($entry['map_name'] ?? '');
        $hash = $entry['source_hash'] ?? null;
        $version = (int) ($entry['catalog_version'] ?? 0);
        $digest = $submission['facts_digest'] ?? null;
        $facts = [
            'display_name' => $entry['display_name'] ?? null,
            'description' => $entry['description'] ?? null,
            'map_version' => $entry['map_version'] ?? null,
            'archive_filename' => $entry['archive_filename'] ?? null,
            'width_elmos' => $entry['width_elmos'] ?? null,
            'height_elmos' => $entry['height_elmos'] ?? null,
            'world_height_min' => $entry['world_height_min'] ?? null,
            'world_height_max' => $entry['world_height_max'] ?? null,
            'min_wind' => $entry['min_wind'] ?? null,
            'max_wind' => $entry['max_wind'] ?? null,
            'tidal_strength' => $entry['tidal_strength'] ?? null,
            'void_water' => $entry['void_water'] ?? null,
            'void_ground' => $entry['void_ground'] ?? null,
            'water_coverage' => $entry['water_coverage'] ?? null,
            'appearance' => ($entry['appearance'] ?? null) ?? (object) [],
            'source_archive' => $entry['source_archive'] ?? null,
            'catalog_version' => $version,
            'facts_digest' => $digest,
            'submitted_by' => $submittedBy,
        ];

        $held = self::heldMap($name);
        $outcome = null;
        if ($held === null) {
            self::refuseTooMany($submittedBy, $now);
            $slug = (string) ($submission['slug'] ?? '');
            if (DB::table('map')->where('slug', $slug)->exists()) {
                $slug = (string) ($submission['slug_alternative'] ?? '');
            }
            $row = ['id' => Rest::uuid(), 'map_name' => $name, 'slug' => $slug, ...$facts, 'source_hash' => $hash,
                'curated_tags' => [], 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            self::insertRow('map', $row);
            $id = $row['id'];
            $outcome = 'stored';
        } else {
            $id = (string) $held['id'];
            if ($held['source_hash'] !== $hash) {
                if (! DB::table('map_source_conflict')->where('map_id', $id)->where('reported_source_hash', $hash)->exists()) {
                    $conflict = self::insertRow('map_source_conflict', [
                        'map_id' => $id, 'source_archive' => $entry['source_archive'] ?? null,
                        'held_source_hash' => $held['source_hash'], 'reported_source_hash' => $hash,
                        'reported_by' => $submittedBy, 'at' => $now,
                    ]);
                    MapShadow::pushConflicts([(int) $conflict]);
                }
                $outcome = 'conflict';
            } elseif ($version > (int) $held['catalog_version']) {
                self::updateMap($held, [...$facts, 'seen_at' => $now, 'updated_at' => $now]);
                $outcome = 'replaced';
            } elseif ($version < (int) $held['catalog_version'] || $held['facts_digest'] === $digest) {
                DB::table('map')->where('id', $id)->update(['seen_at' => $now, 'updated_at' => $now]);
                $outcome = 'unchanged';
            } else {
                $outcome = 'conflict';
            }
        }

        if ($outcome === 'stored' || $outcome === 'replaced') {
            self::replaceDetails($id, $entry);
            MapShadow::pushMaps([$id]);
        }

        return $outcome;
    }

    /** @return array<string, mixed>|null */
    private static function heldMap(string $name): ?array
    {
        $held = DB::table('map')->where('map_name', $name)->lockForUpdate()->first();

        return $held === null ? null : (array) $held;
    }

    /** The trigger on `map` inserts. Thrown out of the batch, so the whole request is refused. */
    private static function refuseTooMany(?string $submittedBy, string $now): void
    {
        if ($submittedBy === null) {
            return;
        }
        $since = Values::stored(now('UTC')->subHour()->format('Y-m-d H:i:s.u'));
        if (DB::table('map')->where('submitted_by', $submittedBy)->where('created_at', '>', $since)->count() >= self::MAPS_PER_HOUR) {
            throw new LocalException('Too many maps submitted in the last hour. Try again later.', 503, '53400');
        }
    }

    /**
     * @param  array<string, mixed>  $held
     * @param  array<string, mixed>  $changes
     */
    private static function updateMap(array $held, array $changes): void
    {
        $after = array_merge($held, $changes);
        $after['appearance'] = json_decode(json_encode($after['appearance']), true);
        $after['curated_tags'] = json_decode((string) $held['curated_tags'], true);
        if (($violation = Tables::violation('map', $after)) !== null) {
            throw $violation;
        }
        $stored = [];
        foreach ($changes as $column => $value) {
            $stored[$column] = Values::write(Tables::COLUMNS['map'][$column], $value);
        }
        DB::table('map')->where('id', $held['id'])->update($stored);
    }

    /**
     * Points and credits, the old set replaced by the new, as a newer reading
     * of an archive may drop some.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function replaceDetails(string $id, array $entry): void
    {
        DB::table('map_point')->where('map_id', $id)->delete();
        $points = [];
        foreach (is_array($entry['points'] ?? null) ? $entry['points'] : [] as $kind => $positions) {
            foreach (array_values(is_array($positions) ? $positions : []) as $ordinal => $point) {
                $row = [
                    'map_id' => $id, 'kind' => (string) $kind, 'ordinal' => $ordinal,
                    'x' => $point['x'] ?? null, 'z' => $point['z'] ?? null, 'y' => $point['y'] ?? null, 'meta' => $point['meta'] ?? null,
                ];
                if (($violation = Tables::violation('map_point', $row)) !== null) {
                    throw $violation;
                }
                $points[] = self::stored('map_point', $row);
            }
        }
        foreach (array_chunk($points, 500) as $chunk) {
            DB::table('map_point')->insert($chunk);
        }

        DB::table('map_author')->where('map_id', $id)->delete();
        $aliases = MapViews::aliases();
        $authors = [];
        foreach (Authors::credits(isset($entry['author']) ? (string) $entry['author'] : null) as $raw) {
            $key = Authors::resolve(Authors::key($raw), $aliases);
            if (Tables::btrim($key) === '') {
                // A clan tag with no name behind it.
                continue;
            }
            $row = ['map_id' => $id, 'credit_index' => count($authors), 'raw' => $raw, 'key' => $key];
            if (($violation = Tables::violation('map_author', $row)) !== null) {
                throw $violation;
            }
            $authors[] = self::stored('map_author', $row);
        }
        if ($authors !== []) {
            DB::table('map_author')->insert($authors);
        }
    }

    /**
     * A whole row checked as Postgres would and inserted. Answers its id.
     *
     * @param  array<string, mixed>  $row
     */
    private static function insertRow(string $table, array $row): int|string
    {
        $decoded = json_decode(json_encode($row), true);
        if (($violation = Tables::violation($table, $decoded)) !== null) {
            throw $violation;
        }
        foreach (Tables::UNIQUE[$table] ?? [] as $index => $columns) {
            $query = DB::table($table);
            foreach ($columns as $column) {
                $query->where($column, $row[$column] ?? null);
            }
            if ($query->exists()) {
                throw new LocalException('duplicate key value violates unique constraint "'.$index.'"', 409, '23505');
            }
        }
        $stored = self::stored($table, $row);
        if (isset($stored['id'])) {
            DB::table($table)->insert($stored);

            return $stored['id'];
        }

        return DB::table($table)->insertGetId($stored);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function stored(string $table, array $row): array
    {
        $types = Tables::COLUMNS[$table];
        $stored = [];
        foreach ($row as $column => $value) {
            $stored[$column] = Values::write($types[$column], $value);
        }

        return $stored;
    }

    /**
     * `clear_map_facts`: a map with reported conflicts deleted, so the next
     * client to submit it starts it afresh. False when nothing was reported.
     */
    private static function clear(string $mapId): bool
    {
        return DB::transaction(function () use ($mapId) {
            if (! DB::table('map_source_conflict')->where('map_id', $mapId)->exists()) {
                return false;
            }
            DB::table('map_source_conflict')->where('map_id', $mapId)->delete();
            DB::table('map')->where('id', $mapId)->delete();
            MapShadow::clear($mapId);

            return true;
        });
    }

    /**
     * `merge_author_keys`: files every given key under `p_into`, moves aliases
     * that pointed at them, and sets the shown name. `refused` when there is
     * nothing to do, `chained` when `p_into` is itself an alias.
     *
     * @param  list<string>  $keys
     */
    private static function merge(mixed $into, array $keys, mixed $name, mixed $note): string
    {
        $into = Tables::btrim(is_string($into) ? $into : '');
        $name = Tables::btrim(is_string($name) ? $name : '');
        $name = $name === '' ? null : $name;
        $note = Tables::btrim(is_string($note) ? $note : '');
        $note = $note === '' ? null : $note;
        if ($into === '') {
            return 'refused';
        }

        return DB::transaction(function () use ($into, $keys, $name, $note) {
            if (DB::table('author_alias')->where('from_key', $into)->exists()) {
                return 'chained';
            }
            $from = array_values(array_unique(array_filter(
                array_map(Tables::btrim(...), $keys),
                fn (string $key) => $key !== '' && $key !== $into,
            )));
            if ($from === [] && $name === null) {
                return 'refused';
            }
            $now = Values::now();
            if ($from !== []) {
                DB::table('author_alias')->whereIn('to_key', $from)->update(['to_key' => $into]);
            }
            foreach ($from as $key) {
                $row = ['from_key' => $key, 'to_key' => $into, 'note' => $note, 'set_at' => $now];
                if (($violation = Tables::violation('author_alias', $row)) !== null) {
                    throw $violation;
                }
                if (DB::table('author_alias')->where('from_key', $key)->exists()) {
                    DB::table('author_alias')->where('from_key', $key)->update(['to_key' => $into, 'note' => $note, 'set_at' => $now]);
                } else {
                    DB::table('author_alias')->insert($row);
                }
            }
            if ($name !== null) {
                $row = ['key' => $into, 'name' => $name, 'set_at' => $now];
                if (($violation = Tables::violation('author_name', $row)) !== null) {
                    throw $violation;
                }
                DB::table('author_name')->updateOrInsert(['key' => $into], ['name' => $name, 'set_at' => $now]);
            }

            return 'merged';
        });
    }

    /**
     * `add_maps_to_pack`: each pasted line matched to a map by name or by
     * archive file name, ignoring case, and the matches added at the end of
     * the pack. Answers every line with the map it found, or null.
     *
     * @param  list<string>  $names
     * @return list<array{wanted: string, map_name: string|null}>
     */
    private static function addToPack(string $packId, array $names): array
    {
        return DB::transaction(function () use ($packId, $names) {
            $wanted = [];
            foreach ($names as $line) {
                $trimmed = Tables::btrim($line);
                if ($trimmed !== '' && ! in_array($trimmed, $wanted, true)) {
                    $wanted[] = $trimmed;
                }
            }
            $maps = DB::table('map')->select('map_name', 'archive_filename')->get()->all();
            $resolved = [];
            foreach ($wanted as $line) {
                $lower = mb_strtolower($line);
                $best = null;
                foreach ($maps as $map) {
                    $exact = mb_strtolower((string) $map->map_name) === $lower;
                    if (! $exact && ($map->archive_filename === null || mb_strtolower((string) $map->archive_filename) !== $lower)) {
                        continue;
                    }
                    if ($best === null || ($exact && ! $best[1])
                        || ($exact === $best[1] && MapViews::compareText((string) $map->map_name, $best[0]) < 0)) {
                        $best = [(string) $map->map_name, $exact];
                    }
                }
                $resolved[] = ['wanted' => $line, 'map_name' => $best[0] ?? null];
            }

            if (! DB::table('map_pack')->where('id', $packId)->exists()) {
                throw new LocalException('insert or update on table "map_pack_map" violates foreign key constraint "map_pack_map_pack_id_fkey"', 409, '23503');
            }
            $next = DB::table('map_pack_map')->where('pack_id', $packId)->max('position');
            $next = $next === null ? 0 : (int) $next + 1;
            $slot = 0;
            foreach (array_values(array_unique(array_filter(array_column($resolved, 'map_name')))) as $mapName) {
                $position = $next + $slot++;
                if (! DB::table('map_pack_map')->where('pack_id', $packId)->where('map_name', $mapName)->exists()) {
                    DB::table('map_pack_map')->insert(['pack_id' => $packId, 'map_name' => $mapName, 'position' => $position]);
                }
            }

            return $resolved;
        });
    }

    /** @return list<string> */
    private static function texts(mixed $values): array
    {
        return is_array($values) ? array_values(array_map('strval', array_filter($values, fn ($value) => $value !== null))) : [];
    }
}

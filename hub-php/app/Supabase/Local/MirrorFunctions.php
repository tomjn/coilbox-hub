<?php

namespace App\Supabase\Local;

use App\Supabase\Client;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The hub mirror functions, done in PHP on the local tables (#600), as
 * production's schema defines them on 19 November 2026. Each keeps its
 * grants and its answers. This class has the mirrors, their reports and the
 * catalogue. {@see ArchiveFunctions} has uploads, removals, mismatch reports
 * and the archive pages.
 *
 * Postgres triggers counted every change to the catalogue in
 * `catalogue_state.changes` and moved `archive.updated_at`. Here the
 * functions do both themselves, through {@see bump()} and {@see touch()}.
 *
 * Game owners and map names are read from the local `game` and `map` tables,
 * so the mirrors move only after games and maps have (see `mirrors:copy`).
 * `is_moderator` stays in Supabase.
 */
final class MirrorFunctions
{
    /** Function => roles granted execute. */
    private const GRANTS = [
        'record_mirror_report' => ['service_role'],
        'set_mirror_health' => ['service_role'],
        'catalogue_source' => ['service_role'],
        'store_catalogue' => ['service_role'],
        'moderation_mirrors' => ['authenticated', 'service_role'],
        'moderation_add_mirror' => ['authenticated', 'service_role'],
        'moderation_remove_mirror' => ['authenticated', 'service_role'],
        'moderation_enable_mirror' => ['authenticated', 'service_role'],
        'begin_archive_upload' => ['service_role'],
        'archive_upload_status' => ['service_role'],
        'remove_archive' => ['authenticated', 'service_role'],
        'dismiss_archive_mismatches' => ['authenticated', 'service_role'],
        'report_archive_mismatch' => ['service_role'],
        'my_archives' => ['authenticated', 'service_role'],
        'moderation_archives' => ['authenticated', 'service_role'],
        'map_mirror_status' => ['authenticated', 'service_role'],
        'map_mirror_sources' => ['anon', 'authenticated', 'service_role'],
        'game_mirror_sources' => ['anon', 'authenticated', 'service_role'],
    ];

    private const READS = ['moderation_mirrors', 'my_archives', 'moderation_archives', 'map_mirror_status', 'map_mirror_sources', 'game_mirror_sources'];

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

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
        $text = fn (string $name) => isset($args[$name]) ? (string) $args[$name] : null;

        try {
            return match ($function) {
                'record_mirror_report' => self::report((string) $text('p_mirror'), is_array($args['p_payload'] ?? null) ? $args['p_payload'] : []),
                'set_mirror_health' => self::health((string) $text('p_mirror'), (bool) ($args['p_healthy'] ?? false)),
                'catalogue_source' => self::catalogueSource(),
                'store_catalogue' => self::storeCatalogue(
                    (int) ($args['p_seq'] ?? 0), (int) ($args['p_changes'] ?? 0), (int) ($args['p_generated_at'] ?? 0),
                    (string) $text('p_body'), (string) $text('p_sig'), $text('p_next_key'),
                ),
                'moderation_mirrors' => self::moderationMirrors($caller),
                'moderation_add_mirror' => self::addMirror($caller, $text('p_id'), $text('p_name'), $text('p_base_url'), $text('p_public_key')),
                'moderation_remove_mirror' => self::removeMirror($caller, (string) $text('p_id')),
                'moderation_enable_mirror' => self::enableMirror($caller, (string) $text('p_id')),
                'begin_archive_upload' => ArchiveFunctions::begin(
                    self::uuid($text('p_user')), (bool) ($args['p_is_moderator'] ?? false),
                    is_array($args['p_facts'] ?? null) ? $args['p_facts'] : [], (int) ($args['p_reservation_seconds'] ?? 0),
                ),
                'archive_upload_status' => ArchiveFunctions::status(
                    self::uuid($text('p_user')), self::uuid($text('p_id')), (int) ($args['p_reservation_seconds'] ?? 0),
                ),
                'remove_archive' => ArchiveFunctions::remove($caller, self::uuid($text('p_id')), $text('p_reason'), $text('p_note')),
                'dismiss_archive_mismatches' => ArchiveFunctions::dismiss($caller, self::uuid($text('p_archive'))),
                'report_archive_mismatch' => ArchiveFunctions::mismatch(
                    (string) $text('p_sha256'), (array) ($args['p_found'] ?? []), $text('p_ip'),
                ),
                'my_archives' => ArchiveFunctions::mine($caller),
                'moderation_archives' => ArchiveFunctions::moderation($caller, $text('p_query')),
                'map_mirror_status' => ArchiveFunctions::mapStatus($caller, self::uuid($text('p_map_id'))),
                'map_mirror_sources' => MirrorSources::maps([(string) $text('p_map_name')])[(string) $text('p_map_name')],
                'game_mirror_sources' => MirrorSources::games([$id = self::uuid($text('p_game_id'))])[$id],
            };
        } catch (QueryException $e) {
            throw new LocalException($e->getMessage(), 503, null);
        }
    }

    /** Counts one change to the catalogue, as the `bump_catalogue_changes` trigger did. */
    public static function bump(int $times = 1): void
    {
        if ($times > 0) {
            DB::table('catalogue_state')->where('id', true)->increment('changes', $times);
        }
    }

    /**
     * Updates archives, moving `updated_at` as the `archive_touch_updated_at`
     * trigger did, and counts a catalogue change for each row that enters or
     * leaves the listed or removed state, as `archive_catalogue_update` did.
     * Answers how many rows changed.
     *
     * @param  array<string, mixed>  $set
     */
    public static function touch(\Illuminate\Database\Query\Builder $archives, array $set, string $now): int
    {
        $rows = (clone $archives)->lockForUpdate()->get(['id', 'state']);
        if ($rows->isEmpty()) {
            return 0;
        }
        $ids = $rows->pluck('id')->all();
        DB::table('archive')->whereIn('id', $ids)->update($set + ['updated_at' => $now]);
        if (array_key_exists('state', $set)) {
            $shown = ['listed', 'removed'];
            self::bump($rows->filter(fn ($row) => in_array($row->state, $shown, true) || in_array($set['state'], $shown, true))->count());
        }

        return count($ids);
    }

    /** A uuid argument, refused as Postgres refuses one it cannot read. */
    public static function uuid(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (preg_match(self::UUID, $value) !== 1) {
            throw new LocalException('invalid input syntax for type uuid: "'.$value.'"', 400, '22P02');
        }

        return strtolower($value);
    }

    /**
     * `record_mirror_report`: lists the mirror's uploads, revives failed ones
     * it now holds, and records which listed archives it holds.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function report(string $mirrorId, array $payload): array
    {
        return DB::transaction(function () use ($mirrorId, $payload) {
            $now = Values::now();
            // The row lock makes two reports from one mirror apply one after the other.
            $mirror = DB::table('mirror')->where('id', $mirrorId)->where('enabled', true)->lockForUpdate()->first();
            if ($mirror === null) {
                return ['outcome' => 'unknown_mirror'];
            }
            $sentAt = isset($payload['sent_at']) ? (int) $payload['sent_at'] : null;
            // An equal value is accepted. An upload report and a sync report can share a second.
            if ($sentAt !== null && $mirror->last_report_sent_at !== null && $sentAt < (int) $mirror->last_report_sent_at) {
                return ['outcome' => 'stale'];
            }
            $held = array_values(array_unique(array_map('strval', is_array($payload['held'] ?? null) ? $payload['held'] : [])));
            $number = fn (string $key) => isset($payload[$key]) ? (int) $payload[$key] : null;

            DB::table('mirror')->where('id', $mirrorId)->update([
                'last_report_at' => $now,
                'last_report_sent_at' => $sentAt,
                'last_catalogue_seq' => $number('catalogue_seq'),
                'last_catalogue_generated_at' => $number('catalogue_generated_at'),
            ]);

            $listing = ['state' => 'listed', 'listed_at' => $now, 'reserved_until' => null];
            $listed = self::touch(
                DB::table('archive')->where('state', 'uploading')->where('upload_mirror_id', $mirrorId)->whereIn('sha256', $held),
                $listing, $now,
            );

            // After the live uploads, so an upload in progress wins over an old
            // one. The newest failed row for each hash is the one tried, and one
            // that would clash with a row since is skipped.
            $revived = 0;
            $failed = DB::table('archive')->where('state', 'failed')->where('upload_mirror_id', $mirrorId)->whereIn('sha256', $held)
                ->orderBy('sha256')->orderByDesc('created_at')->orderBy('id')->get(['id', 'sha256']);
            $tried = [];
            foreach ($failed as $row) {
                if (isset($tried[$row->sha256])) {
                    continue;
                }
                $tried[$row->sha256] = true;
                try {
                    $revived += DB::transaction(fn () => self::touch(
                        DB::table('archive')->where('id', $row->id)->where('state', 'failed'), $listing, $now,
                    ));
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    // Something else took its hash, name or name and version.
                }
            }

            $holding = DB::table('archive')->where('state', 'listed')->whereIn('sha256', $held)->pluck('id')->all();
            $known = DB::table('archive_mirror')->where('mirror_id', $mirrorId)->whereIn('archive_id', $holding)->pluck('archive_id')->all();
            if ($known !== []) {
                DB::table('archive_mirror')->where('mirror_id', $mirrorId)->whereIn('archive_id', $known)->update(['last_seen_at' => $now]);
            }
            $new = array_values(array_diff($holding, $known));
            foreach (array_chunk($new, 500) as $chunk) {
                DB::table('archive_mirror')->insert(array_map(fn ($id) => [
                    'archive_id' => $id, 'mirror_id' => $mirrorId, 'first_seen_at' => $now, 'last_seen_at' => $now,
                ], $chunk));
            }

            $kept = DB::table('archive')->whereIn('sha256', $held)->pluck('id')->all();
            $gone = DB::table('archive_mirror')->where('mirror_id', $mirrorId)->whereNotIn('archive_id', $kept)->pluck('archive_id')->all();
            if ($gone !== []) {
                DB::table('archive_mirror')->where('mirror_id', $mirrorId)->whereIn('archive_id', $gone)->delete();
            }

            $candidates = DB::table('archive_mirror as am')->join('archive as a', 'a.id', '=', 'am.archive_id')
                ->where('am.mirror_id', $mirrorId)->where('a.state', 'listed')->get(['a.filename', 'a.kind', 'a.size'])->all();
            $pick = $candidates === [] ? null : $candidates[array_rand($candidates)];

            return [
                'outcome' => 'recorded',
                'listed' => $listed + $revived,
                'changed' => $listed + $revived + count($new) + count($gone) > 0,
                'check' => $pick === null ? null : ['kind' => $pick->kind, 'size' => (int) $pick->size, 'filename' => $pick->filename],
            ];
        });
    }

    /** `set_mirror_health`: answers whether the mirror's health changed. */
    private static function health(string $mirrorId, bool $healthy): bool
    {
        return DB::transaction(function () use ($mirrorId, $healthy) {
            $was = DB::table('mirror')->where('id', $mirrorId)->lockForUpdate()->value('healthy');
            if ($was === null) {
                return false;
            }
            DB::table('mirror')->where('id', $mirrorId)->update(['healthy' => $healthy, 'last_check_at' => Values::now()]);

            return (bool) $was !== $healthy;
        });
    }

    /**
     * `catalogue_source`: takes the next sequence number and reads the lists
     * under the state row's lock, so the stored change count never runs ahead
     * of the data it was built from.
     *
     * @return array<string, mixed>
     */
    private static function catalogueSource(): array
    {
        return DB::transaction(function () {
            $state = DB::table('catalogue_state')->where('id', true)->lockForUpdate()->first();
            if ($state === null) {
                throw new LocalException('The catalogue state row is missing', 503, null);
            }
            DB::table('catalogue_state')->where('id', true)->update(['next_seq' => (int) $state->next_seq + 1]);

            $mirrors = DB::table('mirror')->where('enabled', true)->orderBy('id')->get(['id', 'base_url', 'public_key'])
                ->map(fn ($m) => ['id' => $m->id, 'base_url' => $m->base_url, 'public_key' => $m->public_key])->all();
            $archives = DB::table('archive')->where('state', 'listed')->orderBy('listed_at')->orderBy('id')->get()
                ->map(fn ($a) => [
                    'kind' => $a->kind, 'size' => (int) $a->size, 'author' => $a->author, 'sha256' => $a->sha256,
                    'version' => $a->version, 'filename' => $a->filename, 'shortname' => $a->shortname,
                    'description' => $a->description, 'internal_name' => $a->internal_name,
                ])->all();
            $removed = DB::table('archive')->where('state', 'removed')->orderBy('removed_at')->orderBy('id')->get()
                ->map(fn ($a) => [
                    'reason' => $a->removal_reason ?? 'removed', 'sha256' => $a->sha256, 'filename' => $a->filename,
                    'removed_at' => $a->removed_at === null ? null : (int) floor((float) self::epoch($a->removed_at)),
                ])->all();

            return [
                'seq' => (int) $state->next_seq, 'changes' => (int) $state->changes,
                'mirrors' => $mirrors, 'removed' => $removed, 'archives' => $archives,
            ];
        });
    }

    /** `store_catalogue`: a lower sequence never replaces a higher one. */
    private static function storeCatalogue(int $seq, int $changes, int $generatedAt, string $body, string $sig, ?string $nextKey): bool
    {
        return DB::transaction(function () use ($seq, $changes, $generatedAt, $body, $sig, $nextKey) {
            $state = DB::table('catalogue_state')->where('id', true)->lockForUpdate()->first();
            if ($state === null) {
                return false;
            }
            $written = $state->seq === null || $seq > (int) $state->seq;
            $set = ['built_changes' => max((int) $state->built_changes, $changes)];
            if ($written) {
                $set += ['seq' => $seq, 'generated_at' => $generatedAt, 'body' => $body, 'sig' => $sig];
                // Only a catalogue that is now served may say which next key mirrors have seen.
                if ($nextKey !== $state->next_key_public) {
                    $set += ['next_key_public' => $nextKey, 'next_key_since_seq' => $nextKey === null ? null : $seq];
                }
            }
            DB::table('catalogue_state')->where('id', true)->update($set);

            return $written;
        });
    }

    /** `moderation_mirrors`: every mirror with its health, for moderators only. */
    private static function moderationMirrors(Caller $caller): ?array
    {
        if ($caller->userId() === null || ! $caller->isModerator()) {
            return null;
        }
        $copies = DB::table('archive_mirror')->groupBy('mirror_id')->selectRaw('mirror_id, count(*) as n')->pluck('n', 'mirror_id')->all();
        $uploads = DB::table('archive')->whereNotNull('upload_mirror_id')->groupBy('upload_mirror_id')
            ->selectRaw('upload_mirror_id, count(*) as n')->pluck('n', 'upload_mirror_id')->all();
        $ts = fn ($value) => Values::read('ts', $value);
        $mirrors = DB::table('mirror')->orderBy('created_at')->orderBy('id')->get()->map(fn ($m) => Values::jsonbKeys([
            'id' => $m->id,
            'name' => $m->name,
            'base_url' => $m->base_url,
            'public_key' => $m->public_key,
            'enabled' => (bool) $m->enabled,
            'healthy' => (bool) $m->healthy,
            'last_report_at' => $ts($m->last_report_at),
            'last_check_at' => $ts($m->last_check_at),
            'last_catalogue_seq' => Values::read('int', $m->last_catalogue_seq),
            'created_at' => $ts($m->created_at),
            'copies' => (int) ($copies[$m->id] ?? 0),
            'uploads' => (int) ($uploads[$m->id] ?? 0),
        ]))->all();

        return ['seq' => Values::read('int', DB::table('catalogue_state')->where('id', true)->value('seq')), 'mirrors' => $mirrors];
    }

    /** `moderation_add_mirror`: answers `added` or why not. */
    private static function addMirror(Caller $caller, ?string $id, ?string $name, ?string $url, ?string $key): string
    {
        $id = Tables::btrim($id ?? '');
        $name = Tables::btrim($name ?? '');
        $url = Tables::btrim($url ?? '');
        $key = Tables::btrim($key ?? '');
        if ($caller->userId() === null || ! $caller->isModerator()) {
            return 'not_allowed';
        }
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/', $id) !== 1) {
            return 'bad_id';
        }
        $length = mb_strlen($name);
        if ($length < 1 || $length > 128) {
            return 'bad_name';
        }
        if (preg_match('~\Ahttps?://[^/?#\s]+(/[^?#\s]*)?\z~u', $url) !== 1 || str_ends_with($url, '/')) {
            return 'bad_url';
        }
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $key) !== 1) {
            return 'bad_key';
        }

        return DB::transaction(function () use ($id, $name, $url, $key) {
            if (DB::table('mirror')->where('id', $id)->lockForUpdate()->exists()) {
                return 'id_taken';
            }
            DB::table('mirror')->insert([
                'id' => $id, 'name' => $name, 'base_url' => $url, 'public_key' => $key,
                'enabled' => true, 'healthy' => false, 'created_at' => Values::now(),
            ]);
            self::bump();

            return 'added';
        });
    }

    /**
     * `moderation_remove_mirror`: a mirror uploads went through stays as a
     * row, switched off, because each of those archives names it.
     */
    private static function removeMirror(Caller $caller, string $id): string
    {
        if ($caller->userId() === null || ! $caller->isModerator()) {
            return 'not_allowed';
        }

        return DB::transaction(function () use ($id) {
            $mirror = DB::table('mirror')->where('id', $id)->lockForUpdate()->first();
            if ($mirror === null) {
                return 'not_found';
            }
            if (DB::table('archive')->where('upload_mirror_id', $id)->exists()) {
                DB::table('mirror')->where('id', $id)->update(['enabled' => false]);
                self::bump((bool) $mirror->enabled ? 1 : 0);

                return 'switched_off';
            }
            DB::table('archive_mirror')->where('mirror_id', $id)->delete();
            DB::table('mirror')->where('id', $id)->delete();
            self::bump();

            return 'removed';
        });
    }

    /** `moderation_enable_mirror`: it stays unhealthy until its next report passes the hub's check. */
    private static function enableMirror(Caller $caller, string $id): string
    {
        if ($caller->userId() === null || ! $caller->isModerator()) {
            return 'not_allowed';
        }

        return DB::transaction(function () use ($id) {
            $mirror = DB::table('mirror')->where('id', $id)->lockForUpdate()->first();
            if ($mirror === null) {
                return 'not_found';
            }
            DB::table('mirror')->where('id', $id)->update(['enabled' => true, 'healthy' => false]);
            self::bump((bool) $mirror->enabled ? 0 : 1);

            return 'switched_on';
        });
    }

    /** Seconds since 1970 of a stored timestamp, with its fraction. */
    public static function epoch(string $stored): string
    {
        return \Carbon\CarbonImmutable::parse($stored, 'UTC')->format('U.u');
    }
}

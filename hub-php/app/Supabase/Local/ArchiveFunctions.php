<?php

namespace App\Supabase\Local;

use App\Domain\Engines\EngineUploaders;
use App\Domain\Points\CommunityPoints;
use App\Supabase\Supabase;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The archive half of the hub mirror functions (#600): uploads, removals,
 * mismatch reports and the pages that list archives, from
 * 20261117120100_hub_mirror_uploads.sql, 20261117130000_hub_mirror_moderation.sql,
 * 20261117140000_archive_page_link.sql, 20261117170000_map_mirror_status.sql
 * and 20261118140000_archive_upload_unknown_map.sql. Called through
 * {@see MirrorFunctions}, which checks the grants.
 */
final class ArchiveFunctions
{
    private const REASONS = ['withdrawn', 'broken', 'wrong_facts', 'rights', 'harmful', 'other'];

    /** Mismatch reports one address may send in an hour. */
    private const REPORTS_PER_HOUR = 20;

    /**
     * `begin_archive_upload`: reserves an upload on a healthy mirror, or
     * answers why not.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public static function begin(?string $user, bool $moderator, array $facts, int $seconds): array
    {
        return DB::transaction(function () use ($user, $moderator, $facts, $seconds) {
            $now = Values::now();
            $until = CarbonImmutable::now('UTC')->addSeconds($seconds);
            $reservedUntil = $until->format('Y-m-d H:i:s.u');
            $epoch = (int) round((float) $until->format('U.u'));
            $fact = fn (string $key) => isset($facts[$key]) ? (string) $facts[$key] : null;
            $engine = $fact('kind') === 'engine';

            // Lapsed reservations free their hash, filename and name.
            MirrorFunctions::touch(DB::table('archive')->where('state', 'uploading')->where('reserved_until', '<', $now), ['state' => 'failed'], $now);

            // An engine comes from a moderator or someone a moderator has allowed, even when asking again for a token.
            if ($engine && ! $moderator && ! EngineUploaders::has($user)) {
                return ['outcome' => 'refused', 'reason' => 'not_engine_uploader'];
            }

            // Asking again for a token: the same uploader, the same facts, still reserved.
            $row = $user === null ? null : DB::table('archive')->where('uploader', $user)->where('state', 'uploading')
                ->where('sha256', $fact('sha256'))->where('filename', $fact('filename'))->where('kind', $fact('kind'))
                ->where('size', (int) ($facts['size'] ?? 0))->first();
            if ($row !== null) {
                $mirror = self::healthyMirror($row->upload_mirror_id, $engine) ?? self::healthyMirror(null, $engine);
                if ($mirror === null) {
                    return ['outcome' => 'refused', 'reason' => $engine ? 'no_engine_mirror' : 'no_mirror'];
                }
                MirrorFunctions::touch(DB::table('archive')->where('id', $row->id), ['reserved_until' => $reservedUntil, 'upload_mirror_id' => $mirror->id], $now);

                return ['id' => $row->id, 'outcome' => 'reissued', 'base_url' => $mirror->base_url, 'mirror_id' => $mirror->id, 'reserved_until' => $epoch];
            }

            // A game archive belongs to the game with exactly this shortname.
            $game = null;
            if ($fact('kind') === 'game') {
                $game = DB::table('game')->where('shortname', $fact('shortname'))->first(['id', 'owner_user_id']);
                if ($game === null) {
                    return ['outcome' => 'refused', 'reason' => 'unknown_game'];
                }
                if ($game->owner_user_id !== $user && ! $moderator) {
                    return ['outcome' => 'refused', 'reason' => 'not_owner'];
                }
            }
            // A map archive needs the map's facts first, because a map is listed only when it has a row.
            if ($fact('kind') === 'map' && ! DB::table('map')->where('map_name', $fact('internal_name'))->exists()) {
                return ['outcome' => 'refused', 'reason' => 'unknown_map'];
            }

            $duplicate = self::duplicate($facts);
            if ($duplicate !== null) {
                return ['outcome' => 'refused', 'reason' => 'duplicate'] + $duplicate;
            }

            $mirror = self::healthyMirror(null, $engine);
            if ($mirror === null) {
                return ['outcome' => 'refused', 'reason' => $engine ? 'no_engine_mirror' : 'no_mirror'];
            }

            $id = (string) \Illuminate\Support\Str::uuid();
            try {
                DB::transaction(fn () => DB::table('archive')->insert([
                    'id' => $id, 'sha256' => $fact('sha256'), 'filename' => $fact('filename'),
                    'filename_lower' => mb_strtolower((string) $fact('filename')), 'kind' => $fact('kind'),
                    'internal_name' => $fact('internal_name'), 'version' => $fact('version'), 'platform' => $fact('platform') ?? '', 'shortname' => $fact('shortname'),
                    'author' => $fact('author'), 'description' => $fact('description'), 'size' => (int) ($facts['size'] ?? 0),
                    'uploader' => $user, 'state' => 'uploading', 'game_id' => $game?->id, 'upload_mirror_id' => $mirror->id,
                    'reserved_until' => $reservedUntil, 'created_at' => $now, 'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException $e) {
                // Another upload took the name between the check and the insert.
                // The index that refused names the check: MariaDB names the key, SQLite its columns.
                preg_match("/for key '(?:archive\\.)?(\\w+)'|UNIQUE constraint failed: archive\\.(\\w+)/", $e->getMessage(), $m);
                $check = match (($m[1] ?? '') ?: ($m[2] ?? '')) {
                    'archive_sha256_idx', 'sha256' => 'sha256',
                    'archive_filename_idx', 'filename_lower' => 'filename',
                    default => 'name_version',
                };

                return ['outcome' => 'refused', 'reason' => 'duplicate'] + (self::duplicate($facts) ?? ['check' => $check, 'existing' => null]);
            }

            return ['id' => $id, 'outcome' => 'created', 'base_url' => $mirror->base_url, 'mirror_id' => $mirror->id, 'reserved_until' => $epoch];
        });
    }

    /**
     * `archive_upload_status`: one of the caller's uploads, its reservation
     * extended while it is still uploading. Null for anybody else's.
     *
     * @return array<string, mixed>|null
     */
    public static function status(?string $user, ?string $id, int $seconds): ?array
    {
        return DB::transaction(function () use ($user, $id, $seconds) {
            $now = Values::now();
            MirrorFunctions::touch(
                DB::table('archive')->where('id', $id)->where('state', 'uploading')->where('reserved_until', '<', $now),
                ['state' => 'failed'], $now,
            );
            $row = $user === null ? null : DB::table('archive')->where('id', $id)->where('uploader', $user)->first();
            if ($row === null) {
                return null;
            }
            $reservedUntil = $row->reserved_until;
            if ($row->state === 'uploading') {
                $reservedUntil = CarbonImmutable::now('UTC')->addSeconds($seconds)->format('Y-m-d H:i:s.u');
                MirrorFunctions::touch(DB::table('archive')->where('id', $id), ['reserved_until' => $reservedUntil], $now);
            }

            return [
                'id' => $row->id, 'kind' => $row->kind, 'state' => $row->state, 'filename' => $row->filename,
                'reserved_until' => $reservedUntil === null ? null : (int) round((float) MirrorFunctions::epoch((string) $reservedUntil)),
            ];
        });
    }

    /**
     * `remove_archive`: a moderator removes a listed archive for any reason.
     * Its uploader or the game's owner may withdraw it.
     */
    public static function remove(Caller $caller, ?string $id, ?string $reason, ?string $note): string
    {
        $user = $caller->userId();
        if ($user === null) {
            return 'not_allowed';
        }
        $moderator = $caller->isModerator();
        $note = Tables::btrim($note ?? '');
        $note = $note === '' ? null : $note;

        return DB::transaction(function () use ($id, $reason, $note, $user, $moderator) {
            $archive = DB::table('archive')->where('id', $id)->lockForUpdate()->first();
            if ($archive === null) {
                return 'not_found';
            }
            if ($reason === null || ! in_array($reason, self::REASONS, true)) {
                return 'bad_reason';
            }
            $owner = $archive->game_id === null ? null : DB::table('game')->where('id', $archive->game_id)->value('owner_user_id');
            if (! $moderator) {
                if ($reason !== 'withdrawn' || ($archive->uploader !== $user && $owner !== $user)) {
                    return 'not_allowed';
                }
                // A note is for moderators to read. Nobody else writes one.
                $note = null;
            }
            if ($archive->state !== 'listed') {
                return 'not_listed';
            }
            if ($reason === 'other' && $note === null) {
                return 'note_required';
            }
            if ($note !== null && mb_strlen($note) > 2000) {
                return 'note_too_long';
            }
            $now = Values::now();
            MirrorFunctions::touch(DB::table('archive')->where('id', $id), [
                'state' => 'removed', 'removed_at' => $now, 'removal_reason' => $reason, 'removal_note' => $note, 'removed_by' => $user,
            ], $now);
            self::handle($id, $user, 'removed', $now);
            CommunityPoints::revoke(CommunityPoints::ARCHIVE, $id);

            return 'removed';
        });
    }

    /** `dismiss_archive_mismatches`: closes an archive's open reports. Answers how many. */
    public static function dismiss(Caller $caller, ?string $archiveId): int
    {
        $user = $caller->userId();
        if ($user === null || ! $caller->isModerator()) {
            return 0;
        }

        return DB::transaction(fn () => self::handle($archiveId, $user, 'dismissed', Values::now()));
    }

    /**
     * `report_archive_mismatch`: records the fields Coilbox found that
     * disagree with a listed archive's facts.
     *
     * @param  array<string, mixed>  $found
     * @return array{outcome: string}
     */
    public static function mismatch(string $sha256, array $found, ?string $ip): array
    {
        return DB::transaction(function () use ($sha256, $found, $ip) {
            $archive = DB::table('archive')->where('sha256', $sha256)->where('state', 'listed')->lockForUpdate()->first();
            if ($archive === null) {
                return ['outcome' => 'unknown_archive'];
            }
            $diff = [];
            foreach (['kind', 'shortname', 'internal_name', 'version'] as $key) {
                if (! array_key_exists($key, $found)) {
                    continue;
                }
                $value = $found[$key];
                $text = $value === null ? null : (is_string($value) ? $value : json_encode($value));
                if ($text !== $archive->{$key}) {
                    $diff[$key] = $value;
                }
            }
            if ($diff === []) {
                return ['outcome' => 'agrees'];
            }
            if ($ip !== null) {
                $open = DB::table('archive_mismatch')->where('archive_id', $archive->id)->where('ip', $ip)->whereNull('handled_at')->exists();
                if ($open) {
                    return ['outcome' => 'already'];
                }
                $hourAgo = CarbonImmutable::now('UTC')->subHour()->format('Y-m-d H:i:s.u');
                if (DB::table('archive_mismatch')->where('ip', $ip)->where('created_at', '>', $hourAgo)->count() >= self::REPORTS_PER_HOUR) {
                    return ['outcome' => 'rate_limited'];
                }
            }
            DB::table('archive_mismatch')->insert([
                'archive_id' => $archive->id, 'found' => Values::json((object) $diff), 'ip' => $ip, 'created_at' => Values::now(),
            ]);

            return ['outcome' => 'recorded'];
        });
    }

    /**
     * `my_archives`: the caller's own uploads, and every upload of a game
     * they own, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function mine(Caller $caller): array
    {
        $user = $caller->userId();
        if ($user === null) {
            return [];
        }
        $owned = DB::table('game')->where('owner_user_id', $user)->pluck('id')->all();
        $rows = DB::table('archive')->where('state', '<>', 'failed')
            ->where(fn ($q) => $q->where('uploader', $user)->orWhereIn('game_id', $owned))
            ->orderByDesc('created_at')->orderBy('id')->get();
        $ts = fn ($value) => Values::read('ts', $value);

        return $rows->map(fn ($a) => Values::jsonbKeys([
            'id' => $a->id,
            'filename' => $a->filename,
            'kind' => $a->kind,
            'state' => $a->state,
            'internal_name' => $a->internal_name,
            'version' => $a->version,
            'created_at' => $ts($a->created_at),
            'listed_at' => $ts($a->listed_at),
            'removed_at' => $ts($a->removed_at),
            'removal_reason' => $a->removal_reason,
            'mine' => $a->uploader === $user,
            'via_game' => $a->game_id !== null && in_array($a->game_id, $owned, true),
        ]))->all();
    }

    /**
     * `moderation_archives`: reported archives first, then the newest
     * listed uploads whose file name holds the query. Null for anybody but a
     * moderator.
     *
     * @return array{reported: list<array<string, mixed>>, recent: list<array<string, mixed>>}|null
     */
    public static function moderation(Caller $caller, ?string $query): ?array
    {
        if ($caller->userId() === null || ! $caller->isModerator()) {
            return null;
        }
        $query = mb_strtolower(Tables::btrim($query ?? ''));

        $reports = [];
        $first = [];
        foreach (DB::table('archive_mismatch')->whereNull('handled_at')->orderBy('created_at')->orderBy('id')->get() as $report) {
            $reports[$report->archive_id][] = Values::jsonbKeys([
                'found' => Values::read('json', $report->found),
                'created_at' => Values::read('ts', $report->created_at),
            ]);
            $first[$report->archive_id] ??= $report->created_at;
        }
        $reported = DB::table('archive')->whereIn('id', array_keys($first))->where('state', 'listed')->get()->all();
        usort($reported, fn ($a, $b) => strcmp($first[$a->id], $first[$b->id]) ?: strcmp($a->id, $b->id));

        $recent = [];
        foreach (DB::table('archive')->where('state', 'listed')->orderByDesc('listed_at')->orderBy('id')->get() as $archive) {
            if ($query === '' || str_contains((string) $archive->filename_lower, $query)) {
                $recent[] = $archive;
                if (count($recent) === 100) {
                    break;
                }
            }
        }

        $rows = self::moderationRows([...$reported, ...$recent]);

        return [
            'recent' => array_map(fn ($a) => $rows[$a->id], $recent),
            'reported' => array_map(fn ($a) => Values::jsonbKeys($rows[$a->id] + ['reports' => $reports[$a->id]]), $reported),
        ];
    }

    /**
     * `map_mirror_status`: every upload of a map's archive except failed
     * ones, newest first, with the mirrors that last reported holding each.
     * Null for anybody `can_edit_map` does not let edit the map.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function mapStatus(Caller $caller, ?string $mapId): ?array
    {
        if (! MapFunctions::canEditMap($caller, (string) $mapId)) {
            return null;
        }
        $name = DB::table('map')->where('id', $mapId)->value('map_name');
        if ($name === null) {
            return [];
        }
        $user = $caller->userId();
        $archives = DB::table('archive')->where('kind', 'map')->where('internal_name', $name)->where('state', '<>', 'failed')
            ->orderByDesc('created_at')->orderBy('id')->get();
        $held = [];
        $copies = DB::table('archive_mirror as am')->join('mirror as m', 'm.id', '=', 'am.mirror_id')
            ->whereIn('am.archive_id', $archives->pluck('id')->all())->orderBy('m.id')
            ->get(['am.archive_id', 'am.last_seen_at', 'm.name', 'm.enabled', 'm.healthy']);
        foreach ($copies as $copy) {
            $held[$copy->archive_id][] = Values::jsonbKeys([
                'name' => $copy->name,
                'linked' => (bool) $copy->enabled && (bool) $copy->healthy,
                'last_seen_at' => Values::read('ts', $copy->last_seen_at),
            ]);
        }
        $ts = fn ($value) => Values::read('ts', $value);

        return $archives->map(fn ($a) => Values::jsonbKeys([
            'filename' => $a->filename,
            'state' => $a->state,
            'created_at' => $ts($a->created_at),
            'listed_at' => $ts($a->listed_at),
            'removed_at' => $ts($a->removed_at),
            'removal_reason' => $a->removal_reason,
            'mine' => $a->uploader === $user,
            'mirrors' => $held[$a->id] ?? [],
        ]))->all();
    }

    /**
     * `archive_moderation_row` for each archive, by id: its facts, its hub
     * page, who uploaded it and links to the copies on healthy mirrors.
     *
     * @param  list<object>  $archives
     * @return array<string, array<string, mixed>>
     */
    private static function moderationRows(array $archives): array
    {
        if ($archives === []) {
            return [];
        }
        $mapNames = array_values(array_unique(array_map(fn ($a) => $a->internal_name, array_filter($archives, fn ($a) => $a->kind === 'map'))));
        $slugs = $mapNames === [] ? [] : DB::table('map')->whereIn('map_name', $mapNames)->pluck('slug', 'map_name')->all();
        $gameIds = array_values(array_unique(array_filter(array_map(fn ($a) => $a->game_id, $archives))));
        $shortnames = $gameIds === [] ? [] : DB::table('game')->whereIn('id', $gameIds)->pluck('shortname', 'id')->all();
        $names = self::displayNames(array_values(array_unique(array_filter(array_map(fn ($a) => $a->uploader, $archives)))));
        $copies = [];
        $held = DB::table('archive_mirror as am')->join('mirror as m', 'm.id', '=', 'am.mirror_id')
            ->whereIn('am.archive_id', array_map(fn ($a) => $a->id, $archives))->where('m.enabled', true)->where('m.healthy', true)
            ->orderBy('m.id')->get(['am.archive_id', 'm.base_url']);
        $byId = [];
        foreach ($archives as $archive) {
            $byId[$archive->id] = $archive;
        }
        foreach ($held as $copy) {
            $archive = $byId[$copy->archive_id];
            $copies[$copy->archive_id][] = $copy->base_url.'/'.$archive->kind.'s/'.MirrorSources::segment((string) $archive->filename);
        }

        $rows = [];
        foreach ($byId as $id => $a) {
            $page = $a->kind === 'map'
                ? (isset($slugs[$a->internal_name]) ? '/map/'.MirrorSources::segment((string) $slugs[$a->internal_name]) : null)
                : (isset($shortnames[$a->game_id]) ? '/games/'.MirrorSources::segment((string) $shortnames[$a->game_id]) : null);
            $rows[$id] = Values::jsonbKeys([
                'id' => $a->id,
                'sha256' => $a->sha256,
                'filename' => $a->filename,
                'kind' => $a->kind,
                'internal_name' => $a->internal_name,
                'version' => $a->version,
                'shortname' => $a->shortname,
                'author' => $a->author,
                'size' => (int) $a->size,
                'listed_at' => Values::read('ts', $a->listed_at),
                'page' => $page,
                'uploader' => $a->uploader,
                'uploader_name' => $a->uploader === null ? null : ($names[$a->uploader] ?? null),
                'copies' => $copies[$id] ?? [],
            ]);
        }

        return $rows;
    }

    /**
     * Display names from Supabase's `profile`, which has not moved. Anyone may
     * read a name there, and the service role holds no grant on it.
     *
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    private static function displayNames(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $names = [];
        foreach (Supabase::anon()->from('profile')->select('user_id,display_name')->in('user_id', $userIds)->get() as $profile) {
            $names[(string) $profile['user_id']] = (string) $profile['display_name'];
        }

        return $names;
    }

    /** An enabled, healthy mirror, one that takes engines when `$engine`: this one when it qualifies, otherwise any at random. */
    private static function healthyMirror(?string $id, bool $engine): ?object
    {
        $query = DB::table('mirror')->where('enabled', true)->where('healthy', true);
        if ($engine) {
            $query->where('takes_engines', true);
        }
        if ($id !== null) {
            return $query->where('id', $id)->first();
        }
        $mirrors = $query->get()->all();

        return $mirrors === [] ? null : $mirrors[array_rand($mirrors)];
    }

    /**
     * `archive_duplicate`: the archive that has not failed and clashes with
     * these facts, checked by hash, then file name, then name and version.
     *
     * @param  array<string, mixed>  $facts
     * @return array{check: string, existing: array<string, mixed>}|null
     */
    private static function duplicate(array $facts): ?array
    {
        $live = DB::table('archive')->where('state', '<>', 'failed');
        $checks = [
            'sha256' => (clone $live)->where('sha256', (string) ($facts['sha256'] ?? '')),
            'filename' => (clone $live)->where('filename_lower', mb_strtolower((string) ($facts['filename'] ?? ''))),
            'name_version' => (clone $live)->where('internal_name', (string) ($facts['internal_name'] ?? ''))
                ->where('version', (string) ($facts['version'] ?? ''))->where('platform', (string) ($facts['platform'] ?? '')),
        ];
        foreach ($checks as $check => $query) {
            $found = $query->first();
            if ($found !== null) {
                return ['check' => $check, 'existing' => Values::jsonbKeys([
                    'id' => $found->id, 'filename' => $found->filename, 'kind' => $found->kind, 'state' => $found->state,
                    'internal_name' => $found->internal_name, 'shortname' => $found->shortname,
                    'version' => $found->version, 'platform' => $found->platform,
                ])];
            }
        }

        return null;
    }

    /** Closes an archive's open mismatch reports, clearing the addresses. Answers how many. */
    private static function handle(?string $archiveId, string $user, string $how, string $now): int
    {
        return DB::table('archive_mismatch')->where('archive_id', $archiveId)->whereNull('handled_at')
            ->update(['handled_at' => $now, 'handled_by' => $user, 'handled_how' => $how, 'ip' => null]);
    }
}

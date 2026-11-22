<?php

namespace App\Supabase\Local;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The grants and row level security policies on the game catalogue, from
 * `supabase/migrations` (20260821100000, 20260821130000, 20260821140000,
 * 20260914200000, 20260918170000 and 20260918180000), and on the picture
 * tables (`asset_read_approved` and the grants in production's schema) and
 * the map tables (production's schema on 19 November 2026) and the mirror
 * tables (20261117120000_hub_mirrors.sql) and the item, report, profile and
 * capability tables (production's schema on 19 November 2026), for anon and
 * authenticated callers. The service role bypasses row level
 * security, as it does in Postgres, but holds only the privileges granted on
 * the picture and map tables.
 */
final class Policies
{
    /**
     * Columns each role may write, per table and command. A table missing
     * here may not be written at all. `*` is every column.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const GRANTS = [
        'game' => ['update' => ['display_name', 'description', 'links', 'conquest_factions']],
        'game_faction' => ['update' => ['tagline', 'description']],
        'game_unit' => ['update' => ['snippet']],
        'game_download_source' => ['insert' => ['*'], 'delete' => ['*']],
        'game_ownership_request' => [
            'insert' => ['game_id', 'requested_by', 'note', 'state', 'decided_by', 'decided_at', 'created_at'],
            'update' => ['state', 'decided_by', 'decided_at'],
        ],
        'map_download_source' => ['insert' => ['*'], 'delete' => ['*']],
        'map_ownership_request' => ['insert' => ['*'], 'update' => ['state', 'decided_by', 'decided_at']],
        'item' => [
            'insert' => ['kind', 'kind_version', 'title', 'description', 'game_name', 'map_name', 'tags', 'container', 'author_id', 'game_key'],
            'update' => ['title', 'description', 'tags', 'deleted_at'],
            'delete' => ['*'],
        ],
        'report' => ['insert' => ['*'], 'update' => ['*']],
        'profile' => ['update' => ['display_name']],
    ];

    /** What anon may write. Anybody may report. */
    private const ANON_GRANTS = ['report' => ['insert' => ['*']]];

    /** Tables anon and authenticated may read at all. */
    private const READABLE = [
        'game', 'game_version', 'game_faction', 'game_unit', 'game_unit_revision', 'game_download_source',
        'game_browse', 'asset',
        'map', 'map_point', 'map_author', 'author_name', 'author_alias', 'map_pack', 'map_pack_map', 'map_download_source',
        'map_mirror_host', 'map_browse', 'map_listing', 'map_pack_entry', 'author_spelling', 'author_display_name', 'author_map_count',
        'item', 'item_facet', 'report', 'profile',
    ];

    /** The only `profile` columns anon and authenticated may read. */
    private const PROFILE_READABLE = ['user_id', 'display_name'];

    /**
     * What the service role may do on each picture and map table. Of the
     * picture tables anon and authenticated may only read `asset`.
     *
     * @var array<string, list<string>>
     */
    private const SERVICE_GRANTS = [
        'asset' => ['select', 'insert', 'update'],
        'asset_event' => ['select'],
        'asset_licence' => ['select'],
        'asset_source_conflict' => ['select', 'insert'],
        'asset_upload_ip' => ['insert'],
        'asset_withdrawal' => ['select'],
        'staged_object_deletion' => ['select'],
        'map' => ['select', 'insert', 'update'],
        'map_point' => ['select', 'insert', 'update', 'delete'],
        'map_author' => ['select', 'insert', 'update', 'delete'],
        'author_name' => ['select', 'insert', 'update'],
        'author_alias' => ['select', 'insert', 'update', 'delete'],
        'author_owner' => ['select', 'insert', 'delete'],
        'map_pack' => ['select', 'insert', 'update', 'delete'],
        'map_pack_map' => ['select', 'insert', 'delete'],
        'map_download_source' => ['select', 'insert', 'update', 'delete'],
        'map_mirror_host' => ['select', 'insert', 'update'],
        'map_ownership_request' => ['select', 'update'],
        'map_source_conflict' => ['select', 'insert'],
        // The mirror tables are written only by their functions, and the
        // service role reads two of them.
        'mirror' => ['select'],
        'catalogue_state' => ['select'],
        'archive' => [],
        'archive_mirror' => [],
        'archive_mismatch' => [],
        // Read only, granted for the copy by 20261124120000_item_tables_service_read.sql.
        'item' => ['select'],
        'item_facet' => ['select'],
        'report' => ['select'],
        'profile' => ['select'],
        'user_capability' => ['select'],
    ];

    /** Map tables anon and authenticated may not read at all. Requests are for the signed in only. */
    private const UNREADABLE = ['author_owner', 'map_source_conflict'];

    /** Refuses a read the caller holds no privilege for, before any policy. */
    public static function readGrant(Caller $caller, string $table): void
    {
        if (Tables::isMap($table)) {
            $allowed = $caller->isService() || ! (in_array($table, self::UNREADABLE, true)
                || ($table === 'map_ownership_request' && $caller->role() !== 'authenticated'));
        } elseif (Tables::isMirror($table)) {
            $allowed = $caller->isService() && in_array('select', self::SERVICE_GRANTS[$table], true);
        } elseif (Tables::isPicture($table)) {
            $allowed = $caller->isService() ? in_array('select', self::SERVICE_GRANTS[$table], true) : $table === 'asset';
        } elseif (Tables::isItem($table)) {
            $allowed = $caller->isService() ? in_array('select', self::SERVICE_GRANTS[$table], true)
                : in_array($table, ['item', 'item_facet', 'profile'], true) || ($table === 'report' && $caller->role() === 'authenticated');
        } else {
            return;
        }
        if (! $allowed) {
            throw new LocalException('permission denied for table '.$table, 403, '42501');
        }
    }

    /**
     * Refuses a read of a column the caller holds no privilege for. Of
     * `profile`, anon and authenticated may read only the id and the name.
     *
     * @param  list<string>  $columns  every column the request names, `*` for all
     */
    public static function readColumns(Caller $caller, string $table, array $columns): void
    {
        if ($table !== 'profile' || $caller->isService()) {
            return;
        }
        if (in_array('*', $columns, true) || array_diff($columns, self::PROFILE_READABLE) !== []) {
            throw new LocalException('permission denied for table profile', 403, '42501');
        }
    }

    /**
     * Refuses a write the caller holds no privilege for, as Postgres does
     * before any policy runs.
     *
     * @param  list<string>  $columns
     */
    public static function grant(Caller $caller, string $table, string $command, array $columns): void
    {
        if ($caller->isService()) {
            if (isset(self::SERVICE_GRANTS[$table]) && ! in_array($command, self::SERVICE_GRANTS[$table], true)) {
                throw new LocalException('permission denied for table '.$table, 403, '42501');
            }

            return;
        }
        $allowed = match ($caller->role()) {
            'authenticated' => self::GRANTS[$table][$command] ?? null,
            'anon' => self::ANON_GRANTS[$table][$command] ?? null,
            default => null,
        };
        if ($allowed !== null && ($allowed === ['*'] || array_diff($columns, $allowed) === [])) {
            return;
        }

        throw new LocalException('permission denied for table '.$table, 403, '42501');
    }

    /** Narrows a read to the rows the caller may see. */
    public static function read(Caller $caller, Builder $query, string $table, string $alias): void
    {
        if ($caller->isService()) {
            return;
        }
        if ($table === 'game_ownership_request' || $table === 'map_ownership_request') {
            self::ownRequests($caller, $query, $alias);

            return;
        }
        if (! in_array($table, self::READABLE, true)) {
            $query->whereRaw('1 = 0');

            return;
        }
        match ($table) {
            'game', 'game_browse' => self::visibleGame($caller, $query, $alias.'.id'),
            'game_faction', 'game_unit' => self::visibleGame($caller, $query, $alias.'.game_id'),
            'game_version' => self::visibleVersion($caller, $query, $alias.'.game_id', $alias.'.version'),
            'game_unit_revision' => self::visibleRevision($caller, $query, $alias),
            'asset' => $query->where($alias.'.moderation', 'approved'),
            'item' => self::visibleItem($caller, $query, $alias),
            'report' => $caller->isModerator() ? null : $query->whereRaw('1 = 0'),
            default => null,
        };
    }

    /** `item_read_live` and `item_read_moderators`: live items, your own, or all of them for a moderator. */
    private static function visibleItem(Caller $caller, Builder $query, string $alias): void
    {
        $userId = $caller->userId();
        if ($userId !== null && $caller->isModerator()) {
            return;
        }
        $query->where(function (Builder $visible) use ($alias, $userId) {
            $visible->whereNull($alias.'.deleted_at');
            if ($userId !== null) {
                $visible->orWhere($alias.'.author_id', $userId);
            }
        });
    }

    /**
     * The rows an update or delete may touch. Rows outside are left alone
     * without an error, as Postgres does.
     */
    public static function using(Caller $caller, Builder $query, string $table, string $command, string $alias): void
    {
        if ($caller->isService()) {
            return;
        }
        // Writes see only what reads see, then the command's own policy.
        self::read($caller, $query, $table, $alias);
        match ($table) {
            'game' => self::ownerOrModerator($caller, $query, $alias.'.id'),
            'game_faction', 'game_unit', 'game_download_source' => self::ownerOrModerator($caller, $query, $alias.'.game_id'),
            'game_ownership_request', 'map_ownership_request' => $caller->isModerator() ? null : $query->whereRaw('1 = 0'),
            'map_download_source' => self::mapEditor($caller, $query, $alias.'.map_id'),
            // `item_update_own` and `item_withdraw_moderators`. Only the author deletes.
            'item' => $command === 'update' && $caller->isModerator() ? null : $query->where($alias.'.author_id', $caller->userId()),
            'report' => $caller->isModerator() ? null : $query->whereRaw('1 = 0'),
            'profile' => $query->where($alias.'.user_id', $caller->userId()),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * The `with check` half: a row being written must pass it or the whole
     * statement fails.
     *
     * @param  array<string, mixed>  $row  the row as it will be stored
     * @param  'insert'|'update'  $command
     */
    public static function check(Caller $caller, string $table, array $row, string $command = 'insert'): void
    {
        if ($caller->isService()) {
            return;
        }
        $passes = match ($table) {
            'item' => ($caller->userId() !== null && ($row['author_id'] ?? null) === $caller->userId())
                || ($command === 'update' && $caller->isModerator()),
            'report' => $command === 'insert' || $caller->isModerator(),
            'profile' => $command === 'update' && $caller->userId() !== null && ($row['user_id'] ?? null) === $caller->userId(),
            'game' => self::ownsGame($caller, (string) $row['id']) || $caller->isModerator(),
            'game_faction', 'game_unit', 'game_download_source' => self::ownsGame($caller, (string) $row['game_id']) || $caller->isModerator(),
            'game_ownership_request', 'map_ownership_request' => array_key_exists('id', $row) && $row['id'] !== null
                ? $caller->isModerator()
                : ($row['requested_by'] ?? null) === $caller->userId() && ($row['state'] ?? null) === 'open',
            'map_download_source' => MapFunctions::canEditMap($caller, (string) $row['map_id']),
            default => false,
        };
        if (! $passes) {
            throw new LocalException('new row violates row-level security policy for table "'.$table.'"', 403, '42501');
        }
    }

    /** `public.game_row_visible`: not hidden, or the caller owns it or moderates. */
    private static function visibleGame(Caller $caller, Builder $query, string $gameId): void
    {
        if (! self::anyHidden() || $caller->isModerator()) {
            return;
        }
        $userId = $caller->userId();
        $query->whereExists(function (Builder $game) use ($gameId, $userId) {
            $game->from('game as vg')->whereColumn('vg.id', $gameId)->where(function (Builder $visible) use ($userId) {
                $visible->whereNull('vg.hidden_at');
                if ($userId !== null) {
                    $visible->orWhere('vg.owner_user_id', $userId);
                }
            });
        });
    }

    /** `game_row_visible` and `game_version_visible` together. */
    private static function visibleVersion(Caller $caller, Builder $query, string $gameId, string $version): void
    {
        if (! self::anyHidden() || $caller->isModerator()) {
            return;
        }
        $userId = $caller->userId();
        $query->whereExists(function (Builder $game) use ($gameId, $version, $userId) {
            $game->from('game as vg')->whereColumn('vg.id', $gameId)->where(function (Builder $visible) use ($gameId, $version, $userId) {
                $visible->where(function (Builder $shown) use ($gameId, $version) {
                    $shown->whereNull('vg.hidden_at')->whereNotExists(function (Builder $hidden) use ($gameId, $version) {
                        $hidden->from('game_version as vv')->whereColumn('vv.game_id', $gameId)
                            ->whereColumn('vv.version', $version)->whereNotNull('vv.hidden_at');
                    });
                });
                if ($userId !== null) {
                    $visible->orWhere('vg.owner_user_id', $userId);
                }
            });
        });
    }

    /** `public.game_unit_revision_visible`. */
    private static function visibleRevision(Caller $caller, Builder $query, string $alias): void
    {
        if (! self::anyHidden() || $caller->isModerator()) {
            return;
        }
        $userId = $caller->userId();
        $query->whereExists(function (Builder $unit) use ($alias, $userId) {
            $unit->from('game_unit as vu')->join('game as vg', 'vg.id', '=', 'vu.game_id')
                ->whereColumn('vu.id', $alias.'.unit_id')
                ->where(function (Builder $visible) use ($alias, $userId) {
                    $visible->where(function (Builder $shown) use ($alias) {
                        $shown->whereNull('vg.hidden_at')->whereNotExists(function (Builder $hidden) use ($alias) {
                            $hidden->from('game_version as vv')->whereColumn('vv.game_id', 'vu.game_id')
                                ->whereColumn('vv.version', $alias.'.version')->whereNotNull('vv.hidden_at');
                        });
                    });
                    if ($userId !== null) {
                        $visible->orWhere('vg.owner_user_id', $userId);
                    }
                });
        });
    }

    private static function ownRequests(Caller $caller, Builder $query, string $alias): void
    {
        if ($caller->role() !== 'authenticated') {
            $query->whereRaw('1 = 0');

            return;
        }
        if (! $caller->isModerator()) {
            $query->where($alias.'.requested_by', $caller->userId());
        }
    }

    private static function ownerOrModerator(Caller $caller, Builder $query, string $gameId): void
    {
        if ($caller->isModerator()) {
            return;
        }
        $userId = $caller->userId();
        $query->whereExists(fn (Builder $game) => $game->from('game as og')->whereColumn('og.id', $gameId)->where('og.owner_user_id', $userId));
    }

    /** `public.can_edit_map(map_id)` as a `using` rule: the maps this caller may edit. */
    private static function mapEditor(Caller $caller, Builder $query, string $mapId): void
    {
        $editable = MapFunctions::editableMapIds($caller);
        if ($editable === null) {
            return;
        }
        $editable === [] ? $query->whereRaw('1 = 0') : $query->whereIn($mapId, $editable);
    }

    private static function ownsGame(Caller $caller, string $gameId): bool
    {
        $userId = $caller->userId();

        return $userId !== null && DB::table('game')->where('id', $gameId)->where('owner_user_id', $userId)->exists();
    }

    /**
     * Whether anything is hidden. While nothing is, visibility needs no
     * question to Supabase about who moderates.
     */
    private static function anyHidden(): bool
    {
        return DB::table('game')->whereNotNull('hidden_at')->exists()
            || DB::table('game_version')->whereNotNull('hidden_at')->exists();
    }
}

<?php

namespace App\Supabase\Local;

/**
 * The game catalogue and picture tables as Postgres declares them: columns in table order
 * with their types, defaults, foreign keys and check constraints. The local
 * store reads this to answer in PostgREST's shapes and to refuse what Postgres
 * would refuse.
 *
 * Types: `uuid`, `text`, `int`, `bool`, `float` (real), `json` (jsonb and
 * text[]) and `ts` (timestamptz).
 */
final class Tables
{
    public const GAME = 'game';

    /** The picture tables (#598). The rest belong to the game catalogue. */
    public const PICTURES = [
        'asset', 'asset_event', 'asset_licence', 'asset_source_conflict', 'asset_upload_ip', 'asset_withdrawal',
        'staged_object_deletion',
    ];

    /** @var array<string, array<string, string>> */
    public const COLUMNS = [
        'game' => [
            'id' => 'uuid', 'shortname' => 'text', 'display_name' => 'text', 'description' => 'text',
            'links' => 'json', 'start_units' => 'json', 'submitted_by' => 'uuid', 'created_at' => 'ts',
            'updated_at' => 'ts', 'owner_user_id' => 'uuid', 'logo_path' => 'text', 'logo_hash' => 'text',
            'banner_path' => 'text', 'banner_hash' => 'text', 'hidden_at' => 'ts', 'hidden_by' => 'uuid',
            'logo_staged_tier' => 'text', 'banner_staged_tier' => 'text', 'featured_at' => 'ts',
            'featured_by' => 'uuid', 'card_path' => 'text', 'card_hash' => 'text', 'card_staged_tier' => 'text',
            'conquest_factions' => 'json', 'base_game' => 'text',
        ],
        'game_version' => [
            'id' => 'int', 'game_id' => 'uuid', 'version' => 'text', 'first_seen_at' => 'ts',
            'last_seen_at' => 'ts', 'hidden_at' => 'ts', 'hidden_by' => 'uuid', 'reported_in_full' => 'bool',
        ],
        'game_faction' => [
            'id' => 'int', 'game_id' => 'uuid', 'key' => 'text', 'name' => 'text', 'logo_path' => 'text',
            'logo_hash' => 'text',
        ],
        'game_unit' => [
            'id' => 'int', 'game_id' => 'uuid', 'unit_name' => 'text', 'full_name' => 'text',
            'faction_key' => 'text', 'build_options' => 'json', 'stats' => 'json', 'facts_digest' => 'text',
            'source_version' => 'text', 'removed_at' => 'ts', 'last_seen_at' => 'ts', 'created_at' => 'ts',
            'updated_at' => 'ts', 'snippet' => 'text', 'morph_targets' => 'json',
        ],
        'game_unit_revision' => [
            'id' => 'int', 'unit_id' => 'int', 'version' => 'text', 'full_name' => 'text',
            'faction_key' => 'text', 'build_options' => 'json', 'stats' => 'json', 'facts_digest' => 'text',
            'recorded_at' => 'ts', 'morph_targets' => 'json',
        ],
        'game_download_source' => [
            'id' => 'int', 'game_id' => 'uuid', 'kind' => 'text', 'value' => 'text', 'asset' => 'text',
            'filename' => 'text', 'sort_order' => 'int',
        ],
        'game_download_offer' => [
            'id' => 'int', 'game_id' => 'uuid', 'kind' => 'text', 'value' => 'text', 'asset' => 'text',
            'filename' => 'text', 'offered_by' => 'uuid', 'state' => 'text', 'decided_by' => 'uuid',
            'decided_at' => 'ts', 'created_at' => 'ts',
        ],
        'game_ownership_request' => [
            'id' => 'int', 'game_id' => 'uuid', 'requested_by' => 'uuid', 'requested_by_name' => 'text',
            'note' => 'text', 'state' => 'text', 'decided_by' => 'uuid', 'decided_at' => 'ts', 'created_at' => 'ts',
        ],
        // A view, worked out by GameBrowse. Read only.
        'game_browse' => [
            'shortname' => 'text', 'display_name' => 'text', 'description' => 'text', 'faction_count' => 'int',
            'unit_count' => 'int', 'item_count' => 'int', 'logo_path' => 'text', 'logo_hash' => 'text',
            'logo_staged_tier' => 'text', 'featured_at' => 'ts', 'downloads' => 'json', 'card_path' => 'text',
            'card_hash' => 'text', 'card_staged_tier' => 'text', 'banner_path' => 'text', 'banner_hash' => 'text',
            'banner_staged_tier' => 'text', 'base_game' => 'text',
        ],
        'asset' => [
            'id' => 'uuid', 'game' => 'text', 'unit_name' => 'text', 'map_name' => 'text', 'variant' => 'text',
            'source_hash' => 'text', 'hash' => 'text', 'encode_profile' => 'text', 'path' => 'text', 'origin' => 'text',
            'tier' => 'text', 'mime' => 'text', 'bytes' => 'int', 'width' => 'int', 'height' => 'int',
            'map_width' => 'int', 'map_height' => 'int', 'source_archive' => 'text', 'seen_at' => 'ts',
            'promoted_at' => 'ts', 'uploaded_by' => 'uuid', 'moderation' => 'text', 'approval_source' => 'text',
            'created_at' => 'ts', 'updated_at' => 'ts', 'world_height_min' => 'float', 'world_height_max' => 'float',
            'rejection_kind' => 'text', 'blob_path' => 'text', 'blob_path_tier' => 'text', 'bytes_missing_at' => 'ts',
            'faction_key' => 'text',
        ],
        'asset_event' => [
            'id' => 'int', 'asset_id' => 'uuid', 'action' => 'text', 'rejection_kind' => 'text', 'actor' => 'uuid',
            'uploader' => 'uuid', 'at' => 'ts', 'uploader_display_name' => 'text', 'uploader_provider_name' => 'text',
        ],
        'asset_licence' => [
            'id' => 'uuid', 'game' => 'text', 'map_name' => 'text', 'licence' => 'text', 'licence_url' => 'text',
            'notes' => 'text', 'checked_at' => 'ts', 'checked_by' => 'text', 'redistribute_extracted' => 'text',
            'redistribute_rendered' => 'text', 'created_at' => 'ts', 'updated_at' => 'ts', 'decision' => 'text',
            'decided_at' => 'ts', 'all_maps' => 'bool',
        ],
        'asset_source_conflict' => [
            'id' => 'int', 'asset_id' => 'uuid', 'source_archive' => 'text', 'held_source_hash' => 'text',
            'reported_source_hash' => 'text', 'reported_by' => 'uuid', 'at' => 'ts', 'acknowledged_at' => 'ts',
            'acknowledged_by' => 'uuid',
        ],
        'asset_upload_ip' => ['id' => 'int', 'asset_id' => 'uuid', 'ip' => 'text', 'at' => 'ts'],
        'asset_withdrawal' => ['id' => 'int', 'asset_id' => 'uuid', 'path' => 'text', 'at' => 'ts', 'withdrawn_at' => 'ts'],
        'staged_object_deletion' => ['path' => 'text', 'reserved_at' => 'ts'],
    ];

    /**
     * Foreign keys, child table => [column, parent table, parent column]. An
     * embed follows one of these either way: from the child it is one row or
     * null, from the parent it is a list.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const FOREIGN_KEYS = [
        'game_version' => ['game_id', 'game', 'id'],
        'game_faction' => ['game_id', 'game', 'id'],
        'game_unit' => ['game_id', 'game', 'id'],
        'game_unit_revision' => ['unit_id', 'game_unit', 'id'],
        'game_download_source' => ['game_id', 'game', 'id'],
        'game_download_offer' => ['game_id', 'game', 'id'],
        'game_ownership_request' => ['game_id', 'game', 'id'],
        'asset_event' => ['asset_id', 'asset', 'id'],
        'asset_source_conflict' => ['asset_id', 'asset', 'id'],
        'asset_upload_ip' => ['asset_id', 'asset', 'id'],
        'asset_withdrawal' => ['asset_id', 'asset', 'id'],
    ];

    /**
     * Column defaults Postgres fills on insert. `now` is the time of the
     * statement and `[]`/`{}` are JSON.
     *
     * @var array<string, array<string, mixed>>
     */
    public const DEFAULTS = [
        'game' => ['links' => '[]', 'conquest_factions' => '[]', 'created_at' => 'now', 'updated_at' => 'now'],
        'game_version' => ['first_seen_at' => 'now', 'last_seen_at' => 'now', 'reported_in_full' => false],
        'game_faction' => [],
        'game_unit' => [
            'build_options' => '[]', 'stats' => '{}', 'morph_targets' => '[]',
            'last_seen_at' => 'now', 'created_at' => 'now', 'updated_at' => 'now',
        ],
        'game_unit_revision' => ['build_options' => '[]', 'stats' => '{}', 'morph_targets' => '[]', 'recorded_at' => 'now'],
        'game_download_source' => ['sort_order' => 0],
        'game_download_offer' => ['state' => 'held', 'created_at' => 'now'],
        'game_ownership_request' => ['state' => 'open', 'created_at' => 'now'],
        'asset' => ['tier' => 'bucket', 'seen_at' => 'now', 'moderation' => 'pending', 'created_at' => 'now', 'updated_at' => 'now'],
        'asset_event' => ['at' => 'now'],
        'asset_licence' => [
            'checked_at' => 'now', 'redistribute_extracted' => 'unknown', 'redistribute_rendered' => 'unknown',
            'created_at' => 'now', 'updated_at' => 'now',
        ],
        'asset_source_conflict' => ['at' => 'now'],
        'asset_upload_ip' => ['at' => 'now'],
        'asset_withdrawal' => ['at' => 'now'],
        'staged_object_deletion' => ['reserved_at' => 'now'],
    ];

    /** Tables whose `id` is a uuid Postgres makes with `gen_random_uuid()`. */
    public const UUID_IDS = ['game', 'asset', 'asset_licence'];

    /** Tables whose `updated_at` a trigger moves on every update. */
    public const TOUCHED = ['game', 'game_unit', 'asset', 'asset_licence'];

    /** @var array<string, list<string>> */
    public const NOT_NULL = [
        'game' => ['id', 'shortname', 'links', 'created_at', 'updated_at', 'conquest_factions'],
        'game_version' => ['game_id', 'version', 'first_seen_at', 'last_seen_at', 'reported_in_full'],
        'game_faction' => ['game_id', 'key', 'name'],
        'game_unit' => ['game_id', 'unit_name', 'build_options', 'stats', 'facts_digest', 'last_seen_at', 'created_at', 'updated_at', 'morph_targets'],
        'game_unit_revision' => ['unit_id', 'version', 'build_options', 'stats', 'facts_digest', 'recorded_at', 'morph_targets'],
        'game_download_source' => ['game_id', 'kind', 'value', 'sort_order'],
        'game_download_offer' => ['game_id', 'kind', 'value', 'offered_by', 'state', 'created_at'],
        'game_ownership_request' => ['game_id', 'requested_by', 'requested_by_name', 'state', 'created_at'],
        'asset' => [
            'id', 'variant', 'source_hash', 'hash', 'encode_profile', 'path', 'origin', 'tier', 'mime', 'bytes', 'width',
            'height', 'source_archive', 'seen_at', 'moderation', 'created_at', 'updated_at',
        ],
        'asset_event' => ['asset_id', 'action', 'at'],
        'asset_licence' => ['id', 'checked_at', 'checked_by', 'redistribute_extracted', 'redistribute_rendered', 'created_at', 'updated_at'],
        'asset_source_conflict' => ['asset_id', 'source_archive', 'held_source_hash', 'reported_source_hash', 'at'],
        'asset_upload_ip' => ['asset_id', 'ip', 'at'],
        'asset_withdrawal' => ['asset_id', 'path', 'at'],
        'staged_object_deletion' => ['path', 'reserved_at'],
    ];

    /**
     * Trimmed length limits from the Postgres check constraints, column =>
     * [min, max]. The constraint is named `<table>_<column>_check`.
     *
     * @var array<string, array<string, array{0: int, 1: int}>>
     */
    public const TRIMMED_LENGTH = [
        'game' => [
            'shortname' => [1, 64], 'display_name' => [1, 256], 'logo_path' => [1, 512], 'logo_hash' => [1, 128],
            'banner_path' => [1, 512], 'banner_hash' => [1, 128], 'card_path' => [1, 512], 'card_hash' => [1, 128],
            'base_game' => [1, 64],
        ],
        'game_version' => ['version' => [1, 64]],
        'game_faction' => ['key' => [1, 128], 'name' => [1, 256], 'logo_path' => [1, 512], 'logo_hash' => [1, 128]],
        'game_unit' => [
            'unit_name' => [1, 128], 'full_name' => [1, 256], 'faction_key' => [1, 128], 'facts_digest' => [1, 128],
            'source_version' => [1, 64],
        ],
        'game_unit_revision' => ['version' => [1, 64], 'full_name' => [1, 256], 'faction_key' => [1, 128], 'facts_digest' => [1, 128]],
        'game_download_source' => ['value' => [1, 512], 'asset' => [1, 256], 'filename' => [1, 256]],
        'game_download_offer' => ['value' => [1, 512], 'asset' => [1, 256], 'filename' => [1, 256]],
        'game_ownership_request' => ['requested_by_name' => [1, 256]],
        'asset' => [
            'blob_path' => [1, 512], 'encode_profile' => [1, 64], 'faction_key' => [1, 128], 'game' => [1, 64],
            'hash' => [1, 128], 'map_name' => [1, 256], 'mime' => [1, 128], 'path' => [1, 512], 'source_archive' => [1, 256],
            'source_hash' => [1, 128], 'unit_name' => [1, 128], 'variant' => [1, 64],
        ],
        'asset_licence' => [
            'checked_by' => [1, 128], 'decision' => [1, 4096], 'game' => [1, 64], 'licence' => [1, 128],
            'licence_url' => [1, 512], 'map_name' => [1, 256], 'notes' => [1, 4096],
        ],
        'asset_source_conflict' => ['held_source_hash' => [1, 128], 'reported_source_hash' => [1, 128], 'source_archive' => [1, 256]],
        'asset_withdrawal' => ['path' => [1, 512]],
        'staged_object_deletion' => ['path' => [1, 512]],
    ];

    /** Untrimmed length limits, column => max. @var array<string, array<string, int>> */
    public const MAX_LENGTH = [
        'game' => ['description' => 4000],
        'game_unit' => ['snippet' => 2000],
        'game_ownership_request' => ['note' => 2000],
    ];

    /** Columns limited to a set of values. @var array<string, array<string, list<string>>> */
    public const ONE_OF = [
        'game' => ['logo_staged_tier' => ['blob', 'bucket'], 'banner_staged_tier' => ['blob', 'bucket'], 'card_staged_tier' => ['bucket']],
        'game_download_source' => ['kind' => ['rapid', 'url', 'github']],
        'game_download_offer' => ['kind' => ['rapid', 'url', 'github'], 'state' => ['held', 'accepted', 'declined']],
        'game_ownership_request' => ['state' => ['open', 'approved', 'declined']],
        'asset' => [
            'approval_source' => ['seed', 'bypass', 'moderator'], 'blob_path_tier' => ['bucket'],
            'moderation' => ['pending', 'approved', 'rejected'], 'origin' => ['extracted', 'rendered', 'uploaded'],
            'rejection_kind' => ['safety', 'editorial', 'unrecorded'], 'tier' => ['bucket', 'static'],
        ],
        'asset_event' => [
            'action' => ['seeded', 'bypassed', 'approved', 'rejected', 'returned', 'conflict_acknowledged', 'replaced'],
            'rejection_kind' => ['safety', 'editorial', 'unrecorded'],
        ],
        'asset_licence' => ['redistribute_extracted' => ['unknown', 'allowed', 'denied'], 'redistribute_rendered' => ['unknown', 'allowed', 'denied']],
    ];

    /** Unique keys, name => columns. @var array<string, array<string, list<string>>> */
    public const UNIQUE = [
        'game' => ['game_pkey' => ['id'], 'game_shortname_idx' => ['shortname']],
        'game_version' => ['game_version_identity_idx' => ['game_id', 'version']],
        'game_faction' => ['game_faction_identity_idx' => ['game_id', 'key']],
        'game_unit' => ['game_unit_identity_idx' => ['game_id', 'unit_name']],
        'game_unit_revision' => ['game_unit_revision_identity_idx' => ['unit_id', 'version']],
        // Partial in Postgres, each for rows whose own identity column is set.
        // A null in any column skips the check, which matches.
        'asset' => [
            'asset_pkey' => ['id'], 'asset_unit_identity_idx' => ['game', 'unit_name', 'variant'],
            'asset_faction_identity_idx' => ['game', 'faction_key', 'variant'], 'asset_map_identity_idx' => ['map_name', 'variant'],
        ],
        'asset_source_conflict' => ['asset_source_conflict_report_idx' => ['asset_id', 'reported_source_hash']],
        'asset_withdrawal' => ['asset_withdrawal_asset_idx' => ['asset_id']],
        'asset_licence' => ['asset_licence_game_idx' => ['game'], 'asset_licence_map_idx' => ['map_name'], 'asset_licence_all_maps_idx' => ['all_maps']],
    ];

    /** The tables the local store answers for. */
    public static function handles(string $table): bool
    {
        return isset(self::COLUMNS[$table]);
    }

    /** Whether a table belongs to the pictures, which move on their own switch. */
    public static function isPicture(string $table): bool
    {
        return in_array($table, self::PICTURES, true);
    }

    /** @return array<string, string> */
    public static function columns(string $table): array
    {
        return self::COLUMNS[$table] ?? throw new LocalException('relation "public.'.$table.'" does not exist', 404, '42P01');
    }

    /**
     * The constraint a row breaks, as Postgres words it, or null when it
     * keeps them all. Not null, trimmed lengths, plain lengths, value sets and
     * the cross column rules the tables declare. `$whole` is false for the
     * changes alone, before they meet the row: the picture tables' cross
     * column rules wait for the whole row.
     *
     * @param  array<string, mixed>  $row
     */
    public static function violation(string $table, array $row, bool $whole = true): ?LocalException
    {
        foreach (self::NOT_NULL[$table] ?? [] as $column) {
            if (array_key_exists($column, $row) && $row[$column] === null) {
                return new LocalException(
                    'null value in column "'.$column.'" of relation "'.$table.'" violates not-null constraint', 400, '23502',
                );
            }
        }
        $check = fn (string $name) => new LocalException(
            'new row for relation "'.$table.'" violates check constraint "'.$name.'"', 400, '23514',
        );
        foreach (self::TRIMMED_LENGTH[$table] ?? [] as $column => [$min, $max]) {
            $value = $row[$column] ?? null;
            if (is_string($value)) {
                $length = mb_strlen(self::btrim($value));
                if ($length < $min || $length > $max) {
                    return $check($table.'_'.$column.'_check');
                }
            }
        }
        foreach (self::MAX_LENGTH[$table] ?? [] as $column => $max) {
            $value = $row[$column] ?? null;
            if (is_string($value) && mb_strlen($value) > $max) {
                return $check($table.'_'.$column.'_check');
            }
        }
        foreach (self::ONE_OF[$table] ?? [] as $column => $allowed) {
            $value = $row[$column] ?? null;
            if ($value !== null && ! in_array($value, $allowed, true)) {
                return $check($table.'_'.$column.'_check');
            }
        }
        if (in_array($table, ['game_download_source', 'game_download_offer'], true)) {
            if (($row['asset'] ?? null) !== null && ($row['kind'] ?? null) !== 'github') {
                return $check($table.'_asset_kind_check');
            }
            if (($row['filename'] ?? null) !== null && ($row['kind'] ?? null) !== 'url') {
                return $check($table.'_filename_kind_check');
            }
        }
        if ($table === 'game_download_offer' && array_key_exists('state', $row)
            && (($row['state'] === 'held') !== (($row['decided_at'] ?? null) === null))) {
            return $check('game_download_offer_decided_check');
        }
        if ($whole && $table === 'asset' && ($name = self::assetViolation($row)) !== null) {
            return $check($name);
        }
        if ($whole && $table === 'asset_event' && ($name = self::eventViolation($row)) !== null) {
            return $check($name);
        }
        if ($whole && $table === 'asset_source_conflict') {
            if (array_key_exists('acknowledged_at', $row) && (($row['acknowledged_at'] ?? null) === null) !== (($row['acknowledged_by'] ?? null) === null)) {
                return $check('asset_source_conflict_acknowledged_check');
            }
            if (isset($row['held_source_hash'], $row['reported_source_hash']) && $row['held_source_hash'] === $row['reported_source_hash']) {
                return $check('asset_source_conflict_differs_check');
            }
        }
        if ($table === 'game') {
            foreach (['links', 'conquest_factions'] as $column) {
                if (array_key_exists($column, $row) && ! (is_array($row[$column]) && array_is_list($row[$column]))) {
                    return $check('game_'.$column.'_array_check');
                }
            }
        }

        return null;
    }

    /**
     * `asset`'s cross column checks. A check whose expression is null passes,
     * as in Postgres, so a column left out of an insert breaks none of them.
     *
     * @param  array<string, mixed>  $row
     */
    private static function assetViolation(array $row): ?string
    {
        $v = fn (string $column) => $row[$column] ?? null;
        $set = fn (string ...$columns) => count(array_filter($columns, fn ($c) => $v($c) !== null));
        $moderation = $v('moderation');
        if (($moderation === 'approved' && $v('approval_source') === null) || ($moderation === 'pending' && $v('approval_source') !== null)) {
            return 'asset_approval_state_check';
        }
        if ($v('blob_path_tier') !== null && $v('blob_path') === null) {
            return 'asset_blob_path_tier_needs_path';
        }
        foreach (['bytes', 'height', 'map_height', 'map_width', 'width'] as $column) {
            if ($v($column) !== null && (int) $v($column) <= 0) {
                return 'asset_'.$column.'_check';
            }
        }
        if ($v('bytes_missing_at') !== null && $v('tier') !== 'bucket') {
            return 'asset_bytes_missing_needs_bucket';
        }
        if ($v('faction_key') !== null && $v('variant') !== 'logo') {
            return 'asset_faction_variant_check';
        }
        if (($v('game') === null) !== ($v('map_name') !== null)) {
            return 'asset_game_scope_check';
        }
        if ($set('world_height_min', 'world_height_max') !== ($v('variant') === 'overlay:height' ? 2 : 0)) {
            return 'asset_height_range_check';
        }
        if ($v('world_height_min') !== null && $v('world_height_max') !== null && (float) $v('world_height_max') < (float) $v('world_height_min')) {
            return 'asset_height_range_order_check';
        }
        if ($set('unit_name', 'map_name', 'faction_key') !== 1) {
            return 'asset_identity_check';
        }
        if ($set('map_width', 'map_height') !== ($v('map_name') === null ? 0 : 2)) {
            return 'asset_map_size_check';
        }
        if ($v('map_name') !== null && ! in_array($v('variant'), ['minimap', 'overlay:metal', 'overlay:type', 'overlay:height'], true)) {
            return 'asset_map_variant_check';
        }
        if ($moderation !== null && (($v('rejection_kind') !== null) !== ($moderation === 'rejected'))) {
            return 'asset_rejection_state_check';
        }
        $variant = (string) $v('variant');
        if ($v('unit_name') !== null && $variant !== 'buildpic' && ! str_starts_with($variant, 'render:')) {
            return 'asset_unit_variant_check';
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private static function eventViolation(array $row): ?string
    {
        $action = $row['action'] ?? null;
        if ((($row['rejection_kind'] ?? null) !== null) !== ($action === 'rejected')) {
            return 'asset_event_kind_check';
        }
        $named = ($row['uploader_display_name'] ?? null) !== null || ($row['uploader_provider_name'] ?? null) !== null;
        if ($named && ! ($action === 'rejected' && ($row['rejection_kind'] ?? null) === 'safety')) {
            return 'asset_event_uploader_name_check';
        }

        return null;
    }

    /** Postgres `btrim` with no characters given trims spaces only. */
    public static function btrim(string $value): string
    {
        return trim($value, ' ');
    }
}

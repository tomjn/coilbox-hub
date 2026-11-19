<?php

namespace App\Supabase\Local;

/**
 * The game catalogue tables as Postgres declares them: columns in table order
 * with their types, defaults, foreign keys and check constraints. The local
 * store reads this to answer in PostgREST's shapes and to refuse what Postgres
 * would refuse.
 *
 * Types: `uuid`, `text`, `int`, `bool`, `json` (jsonb and text[]) and `ts`
 * (timestamptz).
 */
final class Tables
{
    public const GAME = 'game';

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
    ];

    /** Tables whose `updated_at` a trigger moves on every update. */
    public const TOUCHED = ['game', 'game_unit'];

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
    ];

    /** Unique keys, name => columns. @var array<string, array<string, list<string>>> */
    public const UNIQUE = [
        'game' => ['game_pkey' => ['id'], 'game_shortname_idx' => ['shortname']],
        'game_version' => ['game_version_identity_idx' => ['game_id', 'version']],
        'game_faction' => ['game_faction_identity_idx' => ['game_id', 'key']],
        'game_unit' => ['game_unit_identity_idx' => ['game_id', 'unit_name']],
        'game_unit_revision' => ['game_unit_revision_identity_idx' => ['unit_id', 'version']],
    ];

    /** The tables the local store answers for. */
    public static function handles(string $table): bool
    {
        return isset(self::COLUMNS[$table]);
    }

    /** @return array<string, string> */
    public static function columns(string $table): array
    {
        return self::COLUMNS[$table] ?? throw new LocalException('relation "public.'.$table.'" does not exist', 404, '42P01');
    }

    /**
     * The constraint a row breaks, as Postgres words it, or null when it
     * keeps them all. Not null, trimmed lengths, plain lengths, value sets and
     * the cross column rules the tables declare.
     *
     * @param  array<string, mixed>  $row
     */
    public static function violation(string $table, array $row): ?LocalException
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
        if ($table === 'game') {
            foreach (['links', 'conquest_factions'] as $column) {
                if (array_key_exists($column, $row) && ! (is_array($row[$column]) && array_is_list($row[$column]))) {
                    return $check('game_'.$column.'_array_check');
                }
            }
        }

        return null;
    }

    /** Postgres `btrim` with no characters given trims spaces only. */
    public static function btrim(string $value): string
    {
        return trim($value, ' ');
    }
}

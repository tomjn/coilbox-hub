<?php

namespace Tests\Concerns;

use App\Supabase\Local\Values;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * A small game catalogue in the local tables, with the hub reading games from
 * MariaDB, for tests of the catalogue after the move (#597). Supabase calls
 * that remain (pictures, items, mirrors, auth, keeping `game` in step) are
 * faked with empty answers unless a test fakes them first.
 */
trait SeedsGameCatalogue
{
    protected const GAME = '00000000-0000-4000-8000-0000000000aa';

    protected const HIDDEN_GAME = '00000000-0000-4000-8000-0000000000bb';

    protected const OWNER = '11111111-1111-1111-1111-111111111111';

    protected const STRANGER = '22222222-2222-2222-2222-222222222222';

    protected function useLocalCatalogue(): void
    {
        config([
            'hub.games_store' => 'mariadb',
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'pk',
            'hub.supabase.service_role_key' => 'sk',
        ]);
    }

    /** Fakes for the Supabase reads and writes the local store still makes. */
    protected function fakeSupabaseRemainder(array $first = []): void
    {
        Http::fake($first + [
            'https://sb.test/rest/v1/rpc/is_moderator' => Http::response('false'),
            'https://sb.test/rest/v1/rpc/game_mirror_sources' => Http::response([]),
            'https://sb.test/rest/v1/rpc/current_author_name' => Http::response('"Requester"'),
            'https://sb.test/rest/v1/asset*' => Http::response([]),
            'https://sb.test/rest/v1/item*' => Http::response([]),
            'https://sb.test/rest/v1/game?*' => Http::response('', 201),
            'https://sb.test/rest/v1/game' => Http::response('', 201),
        ]);
    }

    protected function seedCatalogue(): void
    {
        $at = fn (string $time) => Values::stored($time);
        DB::table('game')->insert([
            $this->gameRow(self::GAME, 'BA', ['display_name' => 'Balanced Annihilation', 'owner_user_id' => self::OWNER, 'start_units' => '["armcom","corcom"]', 'created_at' => $at('2026-08-21T10:00:00Z')]),
            $this->gameRow(self::HIDDEN_GAME, 'HID', ['hidden_at' => $at('2026-09-01T00:00:00Z'), 'created_at' => $at('2026-08-22T10:00:00Z')]),
        ]);
        DB::table('game_version')->insert([
            ['id' => 1, 'game_id' => self::GAME, 'version' => '1.0', 'first_seen_at' => $at('2026-08-21T10:00:00Z'), 'last_seen_at' => $at('2026-08-21T10:00:00.5Z'), 'reported_in_full' => true],
            ['id' => 2, 'game_id' => self::GAME, 'version' => '2.0', 'first_seen_at' => $at('2026-09-21T10:00:00Z'), 'last_seen_at' => $at('2026-09-21T10:00:00.123456Z'), 'reported_in_full' => false],
            ['id' => 3, 'game_id' => self::HIDDEN_GAME, 'version' => '0.1', 'first_seen_at' => $at('2026-08-22T10:00:00Z'), 'last_seen_at' => $at('2026-08-22T10:00:00Z'), 'reported_in_full' => false],
        ]);
        DB::table('game_faction')->insert([
            ['id' => 1, 'game_id' => self::GAME, 'key' => 'arm', 'name' => 'Arm', 'logo_path' => null, 'logo_hash' => null],
            ['id' => 2, 'game_id' => self::GAME, 'key' => 'core', 'name' => 'Core', 'logo_path' => null, 'logo_hash' => null],
            ['id' => 3, 'game_id' => self::GAME, 'key' => 'random', 'name' => 'Random', 'logo_path' => null, 'logo_hash' => null],
        ]);
        DB::table('game_unit')->insert([
            $this->unitRow(1, self::GAME, 'armcom', ['full_name' => 'Arm Commander', 'faction_key' => 'arm', 'build_options' => '["armsolar","armlab"]', 'stats' => '{"health":3000,"metalCost":0}']),
            $this->unitRow(2, self::GAME, 'armsolar', ['full_name' => 'Solar Collector', 'faction_key' => 'arm', 'stats' => '{"health":340}']),
            $this->unitRow(3, self::GAME, 'corcom', ['full_name' => 'Core Commander', 'faction_key' => 'core']),
            $this->unitRow(4, self::GAME, 'armold', ['full_name' => 'Old Thing', 'faction_key' => 'arm', 'removed_at' => $at('2026-09-21T10:00:00Z')]),
            $this->unitRow(5, self::HIDDEN_GAME, 'secret', ['full_name' => 'Secret']),
        ]);
        DB::table('game_unit_revision')->insert([
            $this->revisionRow(1, 1, '1.0', ['full_name' => 'Arm Commander', 'stats' => '{"health":2500}']),
            $this->revisionRow(2, 1, '2.0', ['full_name' => 'Arm Commander', 'stats' => '{"health":3000,"metalCost":0}']),
            $this->revisionRow(3, 5, '0.1', ['full_name' => 'Secret']),
        ]);
        DB::table('game_download_source')->insert([
            ['id' => 1, 'game_id' => self::GAME, 'kind' => 'rapid', 'value' => 'ba:stable', 'asset' => null, 'filename' => null, 'sort_order' => 0],
        ]);
    }

    /** @return array<string, mixed> */
    private function gameRow(string $id, string $shortname, array $over = []): array
    {
        $now = Values::stored('2026-08-21T10:00:00Z');

        return $over + [
            'id' => $id, 'shortname' => $shortname, 'display_name' => null, 'description' => null, 'links' => '[]',
            'start_units' => null, 'submitted_by' => null, 'created_at' => $now, 'updated_at' => $now, 'owner_user_id' => null,
            'logo_path' => null, 'logo_hash' => null, 'banner_path' => null, 'banner_hash' => null, 'hidden_at' => null,
            'hidden_by' => null, 'logo_staged_tier' => null, 'banner_staged_tier' => null, 'featured_at' => null,
            'featured_by' => null, 'card_path' => null, 'card_hash' => null, 'card_staged_tier' => null,
            'conquest_factions' => '[]', 'base_game' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function unitRow(int $id, string $gameId, string $name, array $over = []): array
    {
        $now = Values::stored('2026-08-21T10:00:00Z');

        return $over + [
            'id' => $id, 'game_id' => $gameId, 'unit_name' => $name, 'full_name' => null, 'faction_key' => null,
            'build_options' => '[]', 'stats' => '{}', 'facts_digest' => 'digest-'.$name, 'source_version' => '2.0',
            'removed_at' => null, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'snippet' => null,
            'morph_targets' => '[]',
        ];
    }

    /** @return array<string, mixed> */
    private function revisionRow(int $id, int $unitId, string $version, array $over = []): array
    {
        return $over + [
            'id' => $id, 'unit_id' => $unitId, 'version' => $version, 'full_name' => null, 'faction_key' => null,
            'build_options' => '[]', 'stats' => '{}', 'facts_digest' => 'digest-'.$id,
            'recorded_at' => Values::stored('2026-08-21T10:00:00Z'), 'morph_targets' => '[]',
        ];
    }
}

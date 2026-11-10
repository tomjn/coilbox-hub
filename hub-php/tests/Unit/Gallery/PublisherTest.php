<?php

namespace Tests\Unit\Gallery;

use App\Domain\Container\Container;
use App\Domain\Container\Json;
use App\Domain\Gallery\Publisher;
use App\Supabase\Supabase;
use Illuminate\Support\Facades\Http;
use stdClass;
use Tests\TestCase;

/** Ported from `lib/gallery/publish.test.ts`. */
class PublisherTest extends TestCase
{
    private const LIFTED_REFUSAL = 'This module holds a piece from Beyond All Reason: barrel. Geometry lifted from a game cannot be uploaded to the hub.';

    private const PART_PIECES = [
        '{"id": "root", "name": "base"}',
        '{"id": "p1", "name": "turret", "partId": "turret-round", "parentId": "root"}',
    ];

    private const LIFTED_BARREL = '{"id": "p2", "name": "barrel", "meshId": "mesh-1", "parentId": "p1", "lifted": {"game": {"name": "Beyond All Reason", "archive": "byar.sdd", "shortname": "BAR"}, "model": "objects3d/armcom.s3o"}}';

    private const OWN_HULL = '{"id": "p3", "name": "hull", "meshId": "mesh-2", "parentId": "root", "ownMesh": {"file": "hull.s3o"}}';

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
    }

    private function j(string $json): mixed
    {
        return Json::decode($json);
    }

    private function presetPayload(): stdClass
    {
        return $this->j('{"gameName": "Beyond All Reason", "mapName": "Comet Catcher Remake", "startPosType": 2, "modOptionValues": {}, "participants": []}');
    }

    private function presetCode(?int $version = null): string
    {
        return Container::encodeCode('preset', $version ?? Container::SUPPORTED_KIND_VERSIONS['preset'], $this->presetPayload());
    }

    private function packCode(array $maps): string
    {
        return Container::encodeCode('setup-pack', Container::SUPPORTED_KIND_VERSIONS['setup-pack'], (object) [
            'game' => (object) ['name' => 'SplinterFaction 0.1.78'],
            'engineVersion' => '.spring',
            'maps' => $maps,
        ]);
    }

    /** A pack that carries blueprints has no fixed size (coilbox#1727). */
    private function oversizedPack(): stdClass
    {
        $buildings = [];
        for ($i = 0; $i < 12000; $i++) {
            $buildings[] = (object) ['def' => 'armsolar', 'offset' => (object) ['x' => $i * 16, 'z' => $i * 32], 'facing' => 0];
        }
        $payload = (object) [
            'games' => [(object) ['name' => 'Beyond All Reason 1.2', 'shortname' => 'BAR']],
            'blueprints' => [(object) ['name' => 'Huge', 'buildings' => $buildings, 'footprints' => new stdClass]],
        ];
        $bytes = strlen($this->containerJson('setup-pack', Container::SUPPORTED_KIND_VERSIONS['setup-pack'], $payload));
        $this->assertGreaterThan(Container::MAX_CONTAINER_BYTES, $bytes);

        return $payload;
    }

    private function containerJson(string $kind, int $version, mixed $payload): string
    {
        return json_encode(Container::make($kind, $version, $payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** A unit builder module (#507), shaped as coilbox's `src/lego/model.ts` writes one. */
    private function moduleCode(array $pieces, mixed $game = null): string
    {
        return Container::encodeCode('module', Container::SUPPORTED_KIND_VERSIONS['module'], (object) [
            'name' => 'Twin barrel turret',
            'packId' => 'coilbox-parts',
            'rootPieceId' => 'root',
            'pieces' => $pieces,
            'game' => $game,
        ]);
    }

    private function ownModuleCode(array $pieces, ?string $geometry = null): string
    {
        $payload = [
            'name' => 'Hull',
            'packId' => 'coilbox-parts',
            'rootPieceId' => 'root',
            'pieces' => $pieces,
            'game' => null,
        ];
        if ($geometry !== null) {
            $payload['geometry'] = $geometry;
        }

        return Container::encodeCode('module', Container::SUPPORTED_KIND_VERSIONS['module'], (object) $payload);
    }

    /** @return list<stdClass> */
    private function partPieces(string ...$extra): array
    {
        return array_map($this->j(...), [...self::PART_PIECES, ...$extra]);
    }

    private function assertAccepted(array $result): array
    {
        $this->assertTrue($result['ok'], $result['reason'] ?? '');

        return $result['accepted'];
    }

    private function assertRefused(array $result): string
    {
        $this->assertFalse($result['ok']);

        return $result['reason'];
    }

    /** The first request's body, as the row PostgREST was asked to insert. */
    private function insertedRow(): array
    {
        $recorded = Http::recorded();
        $this->assertCount(1, $recorded);

        return $recorded[0][0]->data();
    }

    private function fakeInsert(mixed $body, int $status = 201): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
    }

    private function publish(array $fields): array
    {
        return Publisher::publish(Supabase::forToken('t'), 'author-1', $fields);
    }

    public function test_a_preset_code_is_accepted_and_describes_itself(): void
    {
        $accepted = $this->assertAccepted(Publisher::accept($this->presetCode()));

        $this->assertSame('preset', $accepted['kind']);
        $this->assertSame('Beyond All Reason', $accepted['gameName']);
        // The old bare gameName spelling carries no shortname, so there is
        // nothing stable to group on.
        $this->assertNull($accepted['gameKey']);
        $this->assertSame('Comet Catcher Remake', $accepted['mapName']);
    }

    public function test_surrounding_whitespace_from_a_copy_and_paste_is_tolerated(): void
    {
        $this->assertTrue(Publisher::accept("\n  ".$this->presetCode()."  \n")['ok']);
    }

    public function test_a_coilbox_share_link_is_accepted_since_that_is_what_share_copies(): void
    {
        $accepted = $this->assertAccepted(Publisher::accept('coilbox://import?code='.rawurlencode($this->presetCode())));

        $this->assertSame('preset', $accepted['kind']);
        $this->assertSame('Comet Catcher Remake', $accepted['mapName']);
    }

    public function test_a_link_pointing_at_a_remote_file_says_so_rather_than_reading_as_junk(): void
    {
        $reason = $this->assertRefused(Publisher::accept('coilbox://import?url=https://example.com/thing.json'));

        $this->assertStringContainsString('hosted somewhere else', $reason);
    }

    public function test_a_join_link_is_told_apart_from_something_publishable(): void
    {
        $reason = $this->assertRefused(Publisher::accept('coilbox://join?server=example.com&battle=7'));

        $this->assertStringContainsString('does not carry anything to publish', $reason);
    }

    public function test_the_json_contents_of_an_exported_file_are_accepted(): void
    {
        $json = Json::encode(Container::make('preset', Container::SUPPORTED_KIND_VERSIONS['preset'], $this->presetPayload()));

        $this->assertTrue(Publisher::accept($json)['ok']);
    }

    public function test_nothing_pasted_is_a_prompt_not_an_error(): void
    {
        $this->assertStringContainsString('Paste a share link or code', $this->assertRefused(Publisher::accept('   ')));
    }

    public function test_something_that_is_not_from_coilbox_is_turned_away(): void
    {
        $reason = $this->assertRefused(Publisher::accept('https://example.com/not-a-code'));

        $this->assertStringContainsString('not something coilbox made', $reason);
    }

    public function test_a_container_from_a_newer_coilbox_is_refused_rather_than_half_read(): void
    {
        $reason = $this->assertRefused(Publisher::accept($this->presetCode(Container::SUPPORTED_KIND_VERSIONS['preset'] + 1)));

        $this->assertStringContainsString('newer coilbox', $reason);
    }

    public function test_a_campaign_is_refused_because_the_gallery_does_not_carry_them(): void
    {
        $code = Container::encodeCode('campaign', Container::SUPPORTED_KIND_VERSIONS['campaign'], (object) [
            'campaign' => (object) ['id' => 'c', 'name' => 'Test', 'missions' => []],
            'media' => new stdClass,
        ]);

        $this->assertStringContainsString('does not carry', $this->assertRefused(Publisher::accept($code)));
    }

    public function test_a_setup_pack_names_its_game(): void
    {
        $accepted = $this->assertAccepted(Publisher::accept($this->packCode(['All That Simmers v1.1.1'])));

        $this->assertSame('SplinterFaction 0.1.78', $accepted['gameName']);
        $this->assertSame('All That Simmers v1.1.1', $accepted['mapName']);
    }

    public function test_a_setup_pack_with_only_the_exact_archive_name_gets_no_grouping_key_so_a_new_build_never_mints_its_own_facet(): void
    {
        $accepted = $this->assertAccepted(Publisher::accept($this->packCode(['All That Simmers v1.1.1'])));

        $this->assertNull($accepted['gameKey']);
    }

    public function test_a_pack_carrying_several_maps_claims_none_of_them(): void
    {
        $accepted = $this->assertAccepted(Publisher::accept($this->packCode(['One', 'Two'])));

        $this->assertSame('SplinterFaction 0.1.78', $accepted['gameName']);
        $this->assertNull($accepted['mapName']);
    }

    public function test_a_setup_pack_too_big_to_read_back_is_refused_as_a_compressed_code(): void
    {
        $code = Container::encodeCode('setup-pack', Container::SUPPORTED_KIND_VERSIONS['setup-pack'], $this->oversizedPack());

        $this->assertFalse(Publisher::accept($code)['ok']);
    }

    public function test_a_setup_pack_too_big_to_read_back_is_refused_as_pasted_json(): void
    {
        $json = $this->containerJson('setup-pack', Container::SUPPORTED_KIND_VERSIONS['setup-pack'], $this->oversizedPack());

        $this->assertStringContainsString('too large', $this->assertRefused(Publisher::accept($json)));
    }

    public function test_a_pack_published_as_a_collection_of_games_is_named_for_the_first_of_them(): void
    {
        // A pack is no longer a snapshot of one setup, so it carries `games`
        // and may carry no maps at all (coilbox #1400).
        $code = Container::encodeCode('setup-pack', Container::SUPPORTED_KIND_VERSIONS['setup-pack'], $this->j(
            '{"title": "Popular water maps", "games": [{"name": "SplinterFaction 0.1.78", "shortname": "SF"}, {"name": "Beyond All Reason 1.2", "shortname": "BAR"}]}',
        ));

        $accepted = $this->assertAccepted(Publisher::accept($code));

        $this->assertSame('SplinterFaction 0.1.78', $accepted['gameName']);
        $this->assertSame('SF', $accepted['gameKey']);
        $this->assertNull($accepted['mapName']);
    }

    public function test_a_challenge_names_its_game_by_shortname(): void
    {
        $code = Container::encodeCode('challenge', Container::SUPPORTED_KIND_VERSIONS['challenge'], $this->j(
            '{"mode": "conquest", "settings": {"game": {"shortname": "BA"}, "nodeCount": 12, "seed": 7, "title": "BA Conquest Test Run"}}',
        ));

        $accepted = $this->assertAccepted(Publisher::accept($code));

        $this->assertSame('BA', $accepted['gameName']);
        // A challenge only ever has a shortname, so it is the one kind whose
        // gameKey is set as often as its gameName.
        $this->assertSame('BA', $accepted['gameKey']);
        $this->assertNull($accepted['mapName']);
    }

    public function test_the_pinned_build_name_is_what_a_person_reads_the_shortname_is_the_grouping_key(): void
    {
        // Issue #92: the shortname groups challenges for the same game across
        // builds, the build name tells a reader which version it is for.
        $code = Container::encodeCode('challenge', Container::SUPPORTED_KIND_VERSIONS['challenge'], $this->j(
            '{"mode": "warpath", "settings": {"game": {"pinnedName": "Full Name 1.2.3", "shortname": "FN"}}}',
        ));

        $accepted = $this->assertAccepted(Publisher::accept($code));

        $this->assertSame('Full Name 1.2.3', $accepted['gameName']);
        $this->assertSame('FN', $accepted['gameKey']);
    }

    public function test_a_preset_carrying_the_unified_game_field_resolves_its_game_through_it(): void
    {
        $code = Container::encodeCode('preset', Container::SUPPORTED_KIND_VERSIONS['preset'], $this->j(
            '{"game": {"name": "Beyond All Reason 1.2.3", "shortname": "BAR"}, "mapName": "Comet Catcher Remake", "startPosType": 2, "modOptionValues": {}, "participants": []}',
        ));

        $accepted = $this->assertAccepted(Publisher::accept($code));

        $this->assertSame('Beyond All Reason 1.2.3', $accepted['gameName']);
        $this->assertSame('BAR', $accepted['gameKey']);
    }

    public function test_a_scenario_names_its_game_the_same_as_any_other_kind(): void
    {
        // A scenario export wraps the document (which carries setup.gameName)
        // beside its dialogue media.
        $code = Container::encodeCode('scenario', Container::SUPPORTED_KIND_VERSIONS['scenario'], $this->j(
            '{"scenario": {"triggers": [], "zones": [], "setup": {"gameName": "Beyond All Reason"}}, "media": {}}',
        ));

        $accepted = $this->assertAccepted(Publisher::accept($code));

        $this->assertSame('scenario', $accepted['kind']);
        $this->assertSame('Beyond All Reason', $accepted['gameName']);
        // The old spelling is a bare gameName, with no shortname to group by.
        $this->assertNull($accepted['gameKey']);
        $this->assertNull($accepted['mapName']);
    }

    public function test_a_scenario_with_nothing_to_name_its_game_yields_no_game_name(): void
    {
        $code = Container::encodeCode('scenario', Container::SUPPORTED_KIND_VERSIONS['scenario'], $this->j('{"triggers": [], "zones": []}'));

        $accepted = $this->assertAccepted(Publisher::accept($code));

        $this->assertSame('scenario', $accepted['kind']);
        $this->assertNull($accepted['gameName']);
        $this->assertNull($accepted['gameKey']);
        $this->assertNull($accepted['mapName']);
    }

    public function test_a_scenario_names_its_map_wrapped_in_an_export_beside_its_dialogue_media(): void
    {
        // coilbox #2600: the map is at payload.scenario.setup.mapName.
        $code = Container::encodeCode('scenario', Container::SUPPORTED_KIND_VERSIONS['scenario'], $this->j(
            '{"scenario": {"triggers": [], "zones": [], "setup": {"gameName": "Beyond All Reason", "mapName": "Comet Catcher Remake"}}, "media": {}}',
        ));

        $this->assertSame('Comet Catcher Remake', $this->assertAccepted(Publisher::accept($code))['mapName']);
    }

    public function test_a_scenarios_blank_map_name_is_stored_as_no_map_not_as_an_empty_one(): void
    {
        // An empty string would offer a map filter option that matches nothing.
        $code = Container::encodeCode('scenario', Container::SUPPORTED_KIND_VERSIONS['scenario'], $this->j(
            '{"scenario": {"triggers": [], "zones": [], "setup": {"gameName": "BAR", "mapName": "   "}}, "media": {}}',
        ));

        $this->assertNull($this->assertAccepted(Publisher::accept($code))['mapName']);
    }

    public function test_a_bare_scenario_document_with_no_export_wrapper_still_names_its_map(): void
    {
        $code = Container::encodeCode('scenario', Container::SUPPORTED_KIND_VERSIONS['scenario'], $this->j(
            '{"triggers": [], "zones": [], "setup": {"gameName": "BAR", "mapName": "Delta Siege Dry"}}',
        ));

        $this->assertSame('Delta Siege Dry', $this->assertAccepted(Publisher::accept($code))['mapName']);
    }

    public function test_a_scenario_payload_missing_setup_entirely_yields_no_map_not_a_throw(): void
    {
        $code = Container::encodeCode('scenario', Container::SUPPORTED_KIND_VERSIONS['scenario'], $this->j(
            '{"scenario": {"triggers": [], "zones": []}, "media": {}}',
        ));

        $this->assertNull($this->assertAccepted(Publisher::accept($code))['mapName']);
    }

    public function test_a_scenario_whose_setup_is_not_an_object_yields_no_map_not_a_throw(): void
    {
        // Standing in for a much older or malformed export.
        $code = Container::encodeCode('scenario', Container::SUPPORTED_KIND_VERSIONS['scenario'], $this->j(
            '{"scenario": {"triggers": [], "zones": [], "setup": "not an object"}, "media": {}}',
        ));

        $this->assertNull($this->assertAccepted(Publisher::accept($code))['mapName']);
    }

    public function test_a_scenario_whose_payload_is_not_an_object_at_all_yields_no_map_not_a_throw(): void
    {
        $this->assertNull(Publisher::describe('scenario', 'not a payload at all', null)['mapName']);
    }

    public function test_a_name_only_row_and_a_shortname_only_row_for_the_same_real_game_do_not_share_a_grouping_key(): void
    {
        // Issue #50. Nothing in the hub can know the two spellings are the
        // same game, so a filter on "BA" finds the challenge and not the scenario.
        $scenario = Publisher::accept(Container::encodeCode('scenario', Container::SUPPORTED_KIND_VERSIONS['scenario'], $this->j(
            '{"scenario": {"triggers": [], "zones": [], "setup": {"gameName": "Balanced Annihilation V15.9.8"}}, "media": {}}',
        )));
        $challenge = Publisher::accept(Container::encodeCode('challenge', Container::SUPPORTED_KIND_VERSIONS['challenge'], $this->j(
            '{"mode": "conquest", "settings": {"game": {"shortname": "BA"}, "nodeCount": 12, "seed": 7}}',
        )));

        $this->assertTrue($scenario['ok']);
        $this->assertTrue($challenge['ok']);
        $this->assertSame('Balanced Annihilation V15.9.8', $scenario['accepted']['gameName']);
        $this->assertNull($scenario['accepted']['gameKey']);
        $this->assertSame('BA', $challenge['accepted']['gameName']);
        $this->assertSame('BA', $challenge['accepted']['gameKey']);
    }

    public function test_a_blueprint_shows_the_build_its_game_field_carries_not_just_the_shortname(): void
    {
        // Issue #92: the row read "SF" for both columns, so nobody could tell which build it was for.
        $code = Container::encodeCode('blueprint', Container::SUPPORTED_KIND_VERSIONS['blueprint'], $this->j(
            '{"name": "A layout", "buildings": [], "footprints": {}, "game": {"name": "SplinterFaction 0.1.80", "shortname": "SF"}}',
        ));

        $accepted = $this->assertAccepted(Publisher::accept($code));

        $this->assertSame('blueprint', $accepted['kind']);
        $this->assertSame('SplinterFaction 0.1.80', $accepted['gameName']);
        $this->assertSame('SF', $accepted['gameKey']);
    }

    public function test_a_module_of_parts_and_empty_pieces_is_accepted_for_no_game(): void
    {
        $accepted = $this->assertAccepted(Publisher::accept($this->moduleCode($this->partPieces())));

        $this->assertSame('module', $accepted['kind']);
        $this->assertNull($accepted['gameName']);
        $this->assertNull($accepted['gameKey']);
    }

    public function test_a_module_tagged_to_a_game_names_that_game(): void
    {
        $game = $this->j('{"name": "Beyond All Reason", "archive": "byar.sdd", "shortname": "BAR"}');

        $accepted = $this->assertAccepted(Publisher::accept($this->moduleCode($this->partPieces(), $game)));

        $this->assertSame('Beyond All Reason', $accepted['gameName']);
        $this->assertSame('BAR', $accepted['gameKey']);
    }

    public function test_a_module_holding_a_piece_lifted_from_a_game_is_refused_naming_the_piece_and_the_game(): void
    {
        $result = Publisher::accept($this->moduleCode($this->partPieces(self::LIFTED_BARREL)));

        $this->assertSame(['ok' => false, 'reason' => self::LIFTED_REFUSAL], $result);
    }

    public function test_tagging_a_module_to_the_game_its_lifted_piece_came_from_does_not_let_it_through(): void
    {
        $game = $this->j('{"name": "Beyond All Reason", "archive": "byar.sdd", "shortname": "BAR"}');

        $result = Publisher::accept($this->moduleCode($this->partPieces(self::LIFTED_BARREL), $game));

        $this->assertSame(['ok' => false, 'reason' => self::LIFTED_REFUSAL], $result);
    }

    public function test_a_module_holding_an_imported_mesh_with_no_record_of_where_it_came_from_is_refused(): void
    {
        $hull = '{"id": "p3", "name": "hull", "meshId": "mesh-2", "parentId": "root"}';

        $result = Publisher::accept($this->moduleCode($this->partPieces($hull)));

        $this->assertSame([
            'ok' => false,
            'reason' => 'This module holds meshes with no record of where they came from: hull. Only meshes imported from your own model file can be shared.',
        ], $result);
    }

    public function test_a_module_holding_the_makers_own_mesh_with_the_meshes_carried_is_accepted(): void
    {
        $this->assertTrue(Publisher::accept($this->ownModuleCode($this->partPieces(self::OWN_HULL), 'Q0JMRUdP'))['ok']);
    }

    public function test_a_module_holding_the_makers_own_mesh_without_the_meshes_is_refused(): void
    {
        $this->assertSame([
            'ok' => false,
            'reason' => 'This module names meshes it does not carry: hull.',
        ], Publisher::accept($this->ownModuleCode($this->partPieces(self::OWN_HULL))));
    }

    public function test_lifted_geometry_is_refused_even_beside_the_makers_own_meshes(): void
    {
        $result = Publisher::accept($this->ownModuleCode($this->partPieces(self::OWN_HULL, self::LIFTED_BARREL), 'Q0JMRUdP'));

        $this->assertSame(['ok' => false, 'reason' => self::LIFTED_REFUSAL], $result);
    }

    public function test_a_module_envelope_whose_payload_has_no_pieces_is_refused_as_unreadable(): void
    {
        $code = Container::encodeCode('module', Container::SUPPORTED_KIND_VERSIONS['module'], (object) [
            'name' => 'Broken',
            'packId' => 'coilbox-parts',
            'rootPieceId' => 'root',
        ]);

        $this->assertSame(['ok' => false, 'reason' => 'That module could not be read.'], Publisher::accept($code));
    }

    public function test_publish_rejects_a_code_accept_rejects_without_touching_the_database(): void
    {
        Http::fake();

        $outcome = $this->publish(['code' => 'not-a-coilbox-code', 'title' => 'Title', 'description' => '', 'tags' => []]);

        $this->assertFalse($outcome['ok']);
        $this->assertSame('invalid', $outcome['status']);
        $this->assertStringContainsString('not something coilbox made', $outcome['reason']);
        Http::assertNothingSent();
    }

    public function test_publish_rejects_a_blank_title_without_touching_the_database(): void
    {
        Http::fake();

        $outcome = $this->publish(['code' => $this->presetCode(), 'title' => '   ', 'description' => '', 'tags' => []]);

        $this->assertFalse($outcome['ok']);
        $this->assertSame('invalid', $outcome['status']);
        $this->assertSame('Give it a title so people know what it is.', $outcome['reason']);
        Http::assertNothingSent();
    }

    public function test_publish_normalises_tags_the_same_way_the_form_does_trimmed_lowercased_blanks_dropped_capped_at_8(): void
    {
        $this->fakeInsert([['id' => 'item-1']]);

        $this->publish([
            'code' => $this->presetCode(),
            'title' => 'Title',
            'description' => '',
            'tags' => [' Eco ', '', 'RUSH', 'eco', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'],
        ]);

        $this->assertSame(['eco', 'rush', 'eco', 'a', 'b', 'c', 'd', 'e'], $this->insertedRow()['tags']);
    }

    public function test_publish_inserts_as_the_given_author_and_returns_the_stored_item_on_success(): void
    {
        $storedRow = [
            'id' => 'item-1',
            'kind' => 'preset',
            'mode' => null,
            'title' => 'Title',
            'description' => 'a description',
            'game_name' => 'Beyond All Reason',
            'game_key' => null,
            'map_name' => 'Comet Catcher Remake',
            'tags' => ['eco'],
            'author_name' => 'Someone',
            'author_id' => 'author-1',
            'created_at' => '2026-01-01T00:00:00Z',
            'featured_at' => null,
        ];
        $this->fakeInsert([$storedRow]);

        $outcome = $this->publish(['code' => $this->presetCode(), 'title' => 'Title', 'description' => ' a description ', 'tags' => ['eco']]);

        $this->assertTrue($outcome['ok']);
        $this->assertSame($storedRow, $outcome['item']);
        $row = $this->insertedRow();
        $this->assertSame('author-1', $row['author_id']);
        $this->assertSame('a description', $row['description']);
        $this->assertSame('preset', $row['kind']);
    }

    public function test_publish_maps_the_rate_limit_triggers_errcode_to_a_rate_limited_status(): void
    {
        $this->fakeInsert(['message' => 'Too many published in the last hour. Try again later.', 'code' => '53400'], 400);

        $outcome = $this->publish(['code' => $this->presetCode(), 'title' => 'Title', 'description' => '', 'tags' => []]);

        $this->assertFalse($outcome['ok']);
        $this->assertSame('rate_limited', $outcome['status']);
        $this->assertSame('Could not publish it: Too many published in the last hour. Try again later.', $outcome['reason']);
    }

    public function test_publish_maps_any_other_database_error_to_a_storage_error_status(): void
    {
        $this->fakeInsert(['message' => 'connection refused', 'code' => '08006'], 400);

        $outcome = $this->publish(['code' => $this->presetCode(), 'title' => 'Title', 'description' => '', 'tags' => []]);

        $this->assertFalse($outcome['ok']);
        $this->assertSame('storage_error', $outcome['status']);
        $this->assertSame('Could not publish it: connection refused', $outcome['reason']);
    }

    public function test_publish_stores_a_module_of_parts_under_its_title(): void
    {
        $this->fakeInsert([['id' => 'item-1']]);

        $outcome = $this->publish(['code' => $this->moduleCode($this->partPieces()), 'title' => 'Twin barrel turret', 'description' => '', 'tags' => []]);

        $this->assertTrue($outcome['ok']);
        $row = $this->insertedRow();
        $this->assertSame('module', $row['kind']);
        $this->assertSame('Twin barrel turret', $row['title']);
    }

    public function test_publish_refuses_a_module_holding_a_lifted_piece_without_touching_the_database(): void
    {
        Http::fake();

        $outcome = $this->publish([
            'code' => $this->moduleCode($this->partPieces(self::LIFTED_BARREL)),
            'title' => 'Twin barrel turret',
            'description' => '',
            'tags' => [],
        ]);

        $this->assertSame(['ok' => false, 'reason' => self::LIFTED_REFUSAL, 'status' => 'invalid'], $outcome);
        Http::assertNothingSent();
    }
}

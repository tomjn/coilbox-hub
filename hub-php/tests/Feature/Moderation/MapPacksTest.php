<?php

namespace Tests\Feature\Moderation;

use App\Domain\Moderation\MapPacks;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The map pack actions and pages, ported from
 * `app/moderation/map-packs/actions.test.ts`.
 */
class MapPacksTest extends ModerationTestCase
{
    private const PACK_ID = '9e0f0000-0000-4000-8000-000000000001';

    /** @param array<string, mixed> $routes */
    private function world(array $routes = [], bool $moderator = true): void
    {
        $this->supabase($routes + [
            'PATCH /rest/v1/map_pack?' => self::reply([['id' => self::PACK_ID]]),
            'POST /rest/v1/map_pack?' => self::reply([['id' => self::PACK_ID]]),
            'DELETE /rest/v1/map_pack' => self::reply([]),
            'POST /rpc/add_maps_to_pack' => self::reply([]),
        ], moderator: $moderator);
    }

    private function packPost(string $action, array $form, bool $signedIn = true): \Illuminate\Testing\TestResponse
    {
        $request = $signedIn ? $this->signedIn() : $this;

        return $request->from('/moderation/map-packs/'.self::PACK_ID)->post('/moderation/map-packs'.$action, $form);
    }

    public function test_featuring_a_pack_records_who_did_it(): void
    {
        $this->world();

        $this->packPost('/feature', ['id' => self::PACK_ID, 'featured' => 'true'])
            ->assertSessionHas('toggle.message', MapPacks::MESSAGES['featured']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data()['featured_by'] === self::MODERATOR);
    }

    public function test_unfeaturing_clears_both_columns(): void
    {
        $this->world();

        $this->packPost('/feature', ['id' => self::PACK_ID, 'featured' => 'false'])
            ->assertSessionHas('toggle.message', MapPacks::MESSAGES['unfeatured']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['featured_at' => null, 'featured_by' => null]);
    }

    public function test_a_session_without_can_moderate_is_refused_before_any_write(): void
    {
        $this->world(moderator: false);

        $this->packPost('/feature', ['id' => self::PACK_ID, 'featured' => 'true'])->assertSessionHas('toggle.message', MapPacks::MESSAGES['notAllowed']);
        $this->packPost('/add', ['id' => self::PACK_ID, 'names' => 'Isis 1.3'])->assertSessionHas('toggle.message', MapPacks::MESSAGES['notAllowed']);
        $this->packPost('/remove', ['id' => self::PACK_ID, 'map' => 'Isis 1.3'])->assertSessionHas('toggle.message', MapPacks::MESSAGES['notAllowed']);
        $this->assertSame([], $this->sentWrites());
    }

    public function test_a_signed_out_visitor_is_asked_to_sign_in(): void
    {
        $this->world();

        $this->packPost('/save', ['id' => self::PACK_ID, 'title' => 'BAR maps'], signedIn: false)
            ->assertSessionHas('toggle.message', MapPacks::MESSAGES['signedOut']);
    }

    public function test_an_id_that_is_not_a_uuid_never_reaches_the_database(): void
    {
        $this->world();

        $this->packPost('/add', ['id' => 'nope', 'names' => 'Isis 1.3'])->assertSessionHas('toggle.message', MapPacks::MESSAGES['notSent']);
        $this->assertSame([], $this->sent);
    }

    public function test_a_pasted_list_goes_to_add_maps_to_pack_as_trimmed_lines_and_misses_are_named(): void
    {
        $this->world(['POST /rpc/add_maps_to_pack' => self::reply([
            ['wanted' => 'isis_1.3.sd7', 'map_name' => 'Isis 1.3'],
            ['wanted' => 'Nowhere 9', 'map_name' => null],
        ])]);

        $this->packPost('/add', ['id' => self::PACK_ID, 'names' => " isis_1.3.sd7 \n\nNowhere 9\n"])
            ->assertSessionHas('toggle', ['form' => '', 'ok' => true, 'message' => '1 line matched a map. No map is called: Nowhere 9.']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/add_maps_to_pack')
            && $r->data() === ['p_pack_id' => self::PACK_ID, 'p_names' => ['isis_1.3.sd7', 'Nowhere 9']]);
    }

    public function test_an_empty_paste_is_refused_without_a_call(): void
    {
        $this->world();

        $this->packPost('/add', ['id' => self::PACK_ID, 'names' => " \n "])->assertSessionHas('toggle.message', MapPacks::MESSAGES['nothingPasted']);
        $this->assertSame([], $this->sent);
    }

    public function test_adding_to_a_pack_deleted_under_the_form_says_it_is_gone(): void
    {
        $this->world(['POST /rpc/add_maps_to_pack' => self::error('23503', 409)]);

        $this->packPost('/add', ['id' => self::PACK_ID, 'names' => 'Isis 1.3'])->assertSessionHas('toggle.message', MapPacks::MESSAGES['notFound']);
    }

    public function test_saving_a_pack_that_no_longer_exists_is_reported_as_not_found(): void
    {
        $this->world(['PATCH /rest/v1/map_pack?' => self::reply([])]);

        $this->packPost('/save', ['id' => self::PACK_ID, 'title' => 'BAR maps', 'blurb' => ''])->assertSessionHas('toggle.message', MapPacks::MESSAGES['notFound']);
    }

    public function test_a_blank_title_is_refused_before_anything_is_asked_and_keeps_what_was_typed(): void
    {
        $this->world();

        $this->signedIn()->from('/moderation/map-packs')->post('/moderation/map-packs', ['title' => '   ', 'blurb' => 'kept'])
            ->assertRedirect('/moderation/map-packs')
            ->assertSessionHas('toggle.message', MapPacks::MESSAGES['badText'])
            ->assertSessionHasInput('blurb', 'kept');
        $this->assertSame([], $this->sent);
    }

    public function test_starting_a_pack_goes_to_its_page(): void
    {
        $this->world();

        $this->signedIn()->post('/moderation/map-packs', ['title' => 'BAR maps'])->assertRedirect('/moderation/map-packs/'.self::PACK_ID);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/rest/v1/map_pack?')
            && $r->data() === ['title' => 'BAR maps', 'blurb' => null]);
    }

    public function test_removing_a_map_names_the_pack_and_the_map(): void
    {
        $this->world(['DELETE /rest/v1/map_pack_map' => self::reply([])]);

        $this->packPost('/remove', ['id' => self::PACK_ID, 'map' => 'Isis 1.3'])->assertSessionHas('toggle.message', MapPacks::MESSAGES['removed']);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains(urldecode($r->url()), 'pack_id=eq.'.self::PACK_ID.'&map_name=eq.Isis 1.3'));
    }

    public function test_deleting_a_pack_goes_back_to_the_list(): void
    {
        $this->world();

        $this->packPost('/delete', ['id' => self::PACK_ID])->assertRedirect('/moderation/map-packs');
    }

    /** What the licence rule read and the pack reads answer for the two pages. */
    private function packs(): void
    {
        $this->world([
            'GET /rest/v1/asset_licence' => self::reply([]),
            'GET /rest/v1/map_pack_entry' => self::reply([
                ['pack_id' => self::PACK_ID, 'map_name' => 'Isis 1.3', 'slug' => 'isis', 'display_name' => null, 'archive_filename' => 'isis.sd7'],
                ['pack_id' => self::PACK_ID, 'map_name' => 'Gone 2', 'slug' => null, 'display_name' => null, 'archive_filename' => null],
            ], 200, ['Content-Range' => '0-1/2']),
            'GET /rest/v1/map_pack?' => self::reply([['id' => self::PACK_ID, 'title' => 'BAR maps', 'blurb' => 'Every map', 'featured_at' => null]]),
        ]);
    }

    public function test_the_list_shows_each_pack_with_its_count(): void
    {
        $this->packs();

        $html = $this->signedIn()->get('/moderation/map-packs')->assertOk()->getContent();

        $this->assertStringContainsString('>BAR maps</a>', $html);
        $this->assertStringContainsString('2 maps, not featured', $html);
    }

    public function test_one_pack_lists_its_maps_and_says_which_are_not_in_the_catalog(): void
    {
        $this->packs();

        $html = $this->signedIn()->get('/moderation/map-packs/'.self::PACK_ID)->assertOk()->getContent();

        $this->assertStringContainsString('Not featured, so Coilbox does not show it.', $html);
        $this->assertStringContainsString('href="/moderation/maps/isis"', $html);
        $this->assertStringContainsString('Gone 2 (not in the catalog right now)', $html);
        $this->assertStringContainsString('>Every map</textarea>', $html);
    }

    public function test_an_id_that_is_not_a_uuid_is_a_404(): void
    {
        $this->world();

        $this->signedIn()->get('/moderation/map-packs/nope')->assertNotFound();
    }
}

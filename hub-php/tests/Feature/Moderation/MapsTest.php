<?php

namespace Tests\Feature\Moderation;

use App\Domain\Moderation\MapModeration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The map moderation pages and their writes. The feature cases are ported
 * from `app/moderation/maps/actions.test.ts`.
 */
class MapsTest extends ModerationTestCase
{
    private const MAP_ID = '0f8fad5b-0007-4000-8000-000000000042';

    private function feature(array $form, mixed $update = null, bool $moderator = true): \Illuminate\Testing\TestResponse
    {
        $this->supabase(['PATCH /rest/v1/map?' => $update ?? self::reply([['id' => self::MAP_ID]])], moderator: $moderator);

        return $this->signedIn()->from('/moderation/maps/comet')->post('/moderation/maps/feature', $form + ['_form' => 'f1']);
    }

    public function test_a_moderator_featuring_a_map_is_told_so_and_the_write_records_who(): void
    {
        $this->feature(['id' => self::MAP_ID, 'slug' => 'comet', 'featured' => 'true'])
            ->assertRedirect('/moderation/maps/comet')
            ->assertSessionHas('toggle', ['form' => 'f1', 'ok' => true, 'message' => MapModeration::FEATURED_MESSAGES['featured']]);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data()['featured_by'] === self::MODERATOR
            && $r->hasHeader('apikey', 'service-key'));
    }

    public function test_unfeaturing_clears_both_columns_and_says_the_other_thing(): void
    {
        $this->feature(['id' => self::MAP_ID, 'slug' => 'comet', 'featured' => 'false'])
            ->assertSessionHas('toggle.message', MapModeration::FEATURED_MESSAGES['unfeatured']);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['featured_at' => null, 'featured_by' => null]);
    }

    public function test_a_session_without_can_moderate_is_refused_before_any_write(): void
    {
        $this->feature(['id' => self::MAP_ID, 'featured' => 'true'], moderator: false)
            ->assertSessionHas('toggle.message', MapModeration::FEATURED_MESSAGES['notAllowed']);
        $this->assertSame([], $this->sentWrites());
    }

    public function test_a_signed_out_visitor_is_asked_to_sign_in(): void
    {
        $this->supabase();

        $this->post('/moderation/maps/feature', ['id' => self::MAP_ID, 'featured' => 'true'])
            ->assertSessionHas('toggle.message', MapModeration::FEATURED_MESSAGES['signedOut']);
    }

    public function test_an_id_that_is_not_a_uuid_never_reaches_the_database(): void
    {
        $this->feature(['id' => 'not-a-uuid', 'featured' => 'true'])
            ->assertSessionHas('toggle.message', MapModeration::FEATURED_MESSAGES['notSent']);
        $this->assertSame([], $this->sent);
    }

    public function test_a_map_that_no_longer_exists_is_reported_as_not_found(): void
    {
        $this->feature(['id' => self::MAP_ID, 'featured' => 'true'], self::reply([]))
            ->assertSessionHas('toggle.message', MapModeration::FEATURED_MESSAGES['notFound']);
    }

    public function test_a_write_the_database_refuses_is_reported_as_not_saved(): void
    {
        $this->feature(['id' => self::MAP_ID, 'featured' => 'true'], self::error())
            ->assertSessionHas('toggle.message', MapModeration::FEATURED_MESSAGES['notSaved']);
    }

    public function test_clearing_a_map_calls_clear_map_facts_with_the_secret_key(): void
    {
        $this->supabase(['POST /rpc/clear_map_facts' => self::reply(true)]);

        $this->signedIn()->post('/moderation/maps/clear', ['map' => self::MAP_ID])->assertRedirect('/moderation/maps');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/clear_map_facts')
            && $r->data() === ['p_map_id' => self::MAP_ID] && ! $r->hasHeader('Authorization'));
    }

    public function test_saving_tags_writes_the_parsed_field(): void
    {
        $this->supabase(['PATCH /rest/v1/map?' => self::reply([])]);

        $this->signedIn()->post('/moderation/maps/tags', ['map' => self::MAP_ID, 'slug' => 'comet', 'tags' => 'Asymmetric, 1v1 , 1v1'])
            ->assertRedirect('/moderation/maps/comet');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['curated_tags' => ['asymmetric', '1v1']]);
    }

    public function test_the_conflict_queue_lists_the_most_reported_map_first_and_finds_by_name(): void
    {
        $report = fn (int $id, string $map, string $at) => ['id' => $id, 'map_id' => $map, 'source_archive' => 'comet.sd7', 'held_source_hash' => str_repeat('a', 64),
            'reported_source_hash' => str_repeat('b', 64), 'reported_by' => $id === 1 ? null : self::MODERATOR, 'at' => $at];
        $this->supabase([
            'GET /rest/v1/map_source_conflict' => self::reply([$report(1, 'two', '2026-08-19T12:00:00Z'), $report(2, 'one', '2026-08-19T11:00:00Z'), $report(3, 'one', '2026-08-19T10:00:00Z')]),
            'GET /rest/v1/map?select=id,map_name,slug' => self::reply([['id' => 'one', 'map_name' => 'Comet Catcher 1.8', 'slug' => 'comet'], ['id' => 'two', 'map_name' => 'Tangerine 1.1', 'slug' => 'tangerine']]),
            'GET /rest/v1/map?select=map_name,slug' => self::reply([['map_name' => 'Comet Catcher 1.8', 'slug' => 'comet']]),
            'GET /rest/v1/map?select=id,slug,map_name,display_name,featured_at' => self::reply([['id' => self::MAP_ID, 'slug' => 'isis', 'map_name' => 'Isis 1.3', 'display_name' => 'Isis']]),
        ]);

        $html = $this->signedIn()->get('/moderation/maps?q=comet_')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Tangerine 1.1'), strpos($html, '2 reports.'));
        $this->assertStringContainsString('by an account that has closed', $html);
        $this->assertStringContainsString('href="/moderation/trail?account='.self::MODERATOR.'"', $html);
        $this->assertStringContainsString('held aaaaaaaaaaaa', $html);
        $this->assertStringContainsString('>Isis</a>', $html);
        $this->assertMatchesRegularExpression('#href="/moderation/maps"\s+aria-current="page"#', $html);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'map_name=ilike.%comet\_%'));
    }

    public function test_one_map_shows_its_curated_tags_for_editing_and_its_derived_tags_read_only(): void
    {
        $this->supabase([
            'GET /rest/v1/map?' => self::reply([['id' => self::MAP_ID, 'map_name' => 'Comet Catcher 1.8', 'slug' => 'comet', 'display_name' => null, 'curated_tags' => ['1v1'], 'featured_at' => '2026-10-01T00:00:00Z']]),
            'GET /rest/v1/map_listing' => self::reply([['tags' => ['1v1', 'water', 'large']]]),
        ]);

        $html = $this->signedIn()->get('/moderation/maps/comet')->assertOk()->getContent();

        $this->assertStringContainsString('value="1v1"', $html);
        $this->assertStringContainsString('Worked out from the measurements, and not editable: water, large.', $html);
        $this->assertStringContainsString('Featured, so the catalog lists this above the rest.', $html);
        $this->assertStringContainsString('>Unfeature</button>', $html);
        $this->assertStringContainsString('href="/moderation/maps"', $html);
    }

    public function test_a_map_that_does_not_exist_is_a_404(): void
    {
        $this->supabase(['GET /rest/v1/map?' => self::reply([]), 'GET /rest/v1/map_listing' => self::reply([])]);

        $this->signedIn()->get('/moderation/maps/nope')->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Moderation;

use App\Domain\Moderation\GameDecisions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The authors page and the games queue, with their writes.
 */
class AuthorsAndGamesTest extends ModerationTestCase
{
    public function test_ticked_keys_open_the_merge_form_with_the_busiest_key_kept(): void
    {
        $this->supabase([
            'GET /rest/v1/author_map_count?select=key,name,maps&key=eq.beherith' => self::reply([['key' => 'beherith', 'name' => 'Beherith', 'maps' => 40]]),
            'GET /rest/v1/author_map_count?select=key,name,maps&key=eq.bherith' => self::reply([['key' => 'bherith', 'name' => 'bherith', 'maps' => 1]]),
            'GET /rest/v1/author_map_count' => self::reply([['key' => 'beherith', 'name' => 'Beherith', 'maps' => 40], ['key' => 'bherith', 'name' => 'bherith', 'maps' => 1]]),
            'GET /rest/v1/author_spelling' => fn (Request $r) => self::reply(str_contains($r->url(), 'bherith') && ! str_contains($r->url(), 'beherith')
                ? [['key' => 'bherith', 'raw' => 'bherith', 'credits' => 1]]
                : [['key' => 'beherith', 'raw' => 'Beherith', 'credits' => 3]]),
            'GET /rest/v1/author_alias' => self::reply([
                ['from_key' => 'original from beherith', 'to_key' => 'beherith', 'note' => 'Same person', 'set_at' => '2026-08-19T10:00:00Z'],
            ]),
        ]);

        $html = $this->signedIn()->get('/moderation/authors?q=her&merge=beherith&merge=bherith&outcome=chained')->assertOk()->getContent();

        $this->assertStringContainsString('Merge 2 keys into one author', $html);
        $this->assertMatchesRegularExpression('/name="into" value="beherith"\s+checked/', $html);
        $this->assertStringContainsString("The key you kept is itself merged into another author.", $html);
        $this->assertStringContainsString('Merged in: <span class="font-mono">original from beherith</span>', $html);
        $this->assertStringContainsString('Every key containing &quot;her&quot;, by how many maps they made.', $html);
        $this->assertMatchesRegularExpression('/name="merge" value="bherith"\s+checked/', $html);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'key=ilike.%her%'));
    }

    public function test_a_merge_is_one_call_and_a_refusal_keeps_the_ticked_keys_in_the_url(): void
    {
        $this->supabase(['POST /rpc/merge_author_keys' => self::reply('refused')]);

        $this->signedIn()->post('/moderation/authors/merge', [
            'into' => 'beherith', 'key' => ['beherith', 'original from beherith'], 'name' => 'Beherith', 'typed_name' => '  ', 'note' => '', 'q' => 'her',
        ])->assertRedirect('/moderation/authors?outcome=refused&q=her&merge=beherith&merge=original+from+beherith');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/merge_author_keys') && $r->data() === [
            'p_into' => 'beherith', 'p_keys' => ['beherith', 'original from beherith'], 'p_name' => 'Beherith', 'p_note' => null,
        ] && ! $r->hasHeader('Authorization'));
    }

    public function test_a_typed_name_wins_and_a_merge_that_worked_drops_the_ticks(): void
    {
        $this->supabase(['POST /rpc/merge_author_keys' => self::reply('merged')]);

        $this->signedIn()->post('/moderation/authors/merge', ['into' => 'a', 'key' => ['a', 'b'], 'name' => 'A', 'typed_name' => 'Typed'])
            ->assertRedirect('/moderation/authors?outcome=merged');

        Http::assertSent(fn (Request $r) => ($r->data()['p_name'] ?? null) === 'Typed');
    }

    public function test_unmerging_deletes_the_alias(): void
    {
        $this->supabase(['DELETE /rest/v1/author_alias' => self::reply([])]);

        $this->signedIn()->post('/moderation/authors/unmerge', ['from' => 'bherith'])->assertRedirect('/moderation/authors');

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'from_key=eq.bherith'));
    }

    private function gamesWorld(): void
    {
        $this->supabase([
            'GET /rest/v1/game_download_offer' => self::reply([['id' => 5, 'kind' => 'github', 'value' => 'beyond-all-reason/Beyond-All-Reason', 'asset' => 'bar.sdz', 'filename' => null, 'created_at' => '2026-10-01T00:00:00Z', 'game' => ['shortname' => 'BYAR']]]),
            'GET /rest/v1/game_ownership_request' => self::reply([['id' => 3, 'note' => 'I maintain it', 'created_at' => '2026-10-01T00:00:00Z', 'requested_by_name' => 'Tom', 'game' => ['shortname' => 'BA']]]),
            'GET /rest/v1/game?select=shortname,hidden_at' => self::reply([['shortname' => 'XTA', 'hidden_at' => '2026-10-01T00:00:00Z']]),
            'GET /rest/v1/game_version' => self::reply([['version' => '1.9.0', 'hidden_at' => '2026-10-01T00:00:00Z', 'game' => ['shortname' => 'BA']]]),
            'GET /rest/v1/game?select=shortname,featured_at' => self::reply([['shortname' => 'BYAR', 'featured_at' => '2026-10-01T00:00:00Z']]),
        ]);
    }

    public function test_the_games_queue_draws_requests_offers_hidden_rows_and_features(): void
    {
        $this->gamesWorld();

        $html = $this->signedIn()->get('/moderation/games?visibility=game-shown')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Ownership requests - Moderation - Coilbox Hub</title>', $html);
        $this->assertStringContainsString('I maintain it', $html);
        $this->assertStringContainsString('BYAR · GitHub repo', $html);
        $this->assertStringContainsString('beyond-all-reason/Beyond-All-Reason, picking bar.sdz', $html);
        $this->assertStringContainsString('>Game shown again.</p>', $html);
        $this->assertStringContainsString('BA 1.9.0', $html);
        $this->assertStringContainsString('>Unfeature</button>', $html);
    }

    public function test_a_flash_key_nobody_wrote_puts_no_words_on_the_page(): void
    {
        $this->gamesWorld();

        $this->signedIn()->get('/moderation/games?visibility=You+have+been+hacked')->assertOk()->assertDontSee('You have been hacked');
    }

    public function test_approving_a_request_moves_ownership_in_the_same_breath(): void
    {
        $this->supabase([
            'GET /rest/v1/game_ownership_request' => self::reply([['game_id' => 'g1', 'state' => 'open', 'requested_by' => 'u2', 'game' => ['shortname' => 'BA']]]),
            'PATCH /rest/v1/game_ownership_request' => self::reply([]),
            'PATCH /rest/v1/game?' => self::reply([]),
        ]);

        $this->signedIn()->post('/moderation/games/decide', ['request_id' => '3', 'approve' => 'true'])->assertRedirect('/moderation/games');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), 'game_ownership_request?id=eq.3')
            && $r->data()['state'] === 'approved' && $r->data()['decided_by'] === self::MODERATOR);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/rest/v1/game?id=eq.g1') && $r->data() === ['owner_user_id' => 'u2']);
    }

    public function test_unhiding_from_the_queue_redirects_with_the_key_of_the_confirmation(): void
    {
        $this->supabase([
            'GET /rest/v1/game?' => self::reply([['id' => 'g1']]),
            'PATCH /rest/v1/game?' => self::reply([]),
        ]);

        $this->signedIn()->post('/moderation/games/visibility', ['shortname' => 'XTA', 'hidden' => 'false', 'onSuccess' => 'moderation'])
            ->assertRedirect('/moderation/games?visibility=game-shown');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['hidden_at' => null, 'hidden_by' => null]);
    }

    public function test_featuring_a_game_nobody_has_is_not_found(): void
    {
        $this->supabase(['PATCH /rest/v1/game?' => self::reply([])]);

        $this->signedIn()->from('/moderation/games')->post('/moderation/games/feature', ['shortname' => 'NOPE', 'featured' => 'true'])
            ->assertSessionHas('toggle.message', GameDecisions::FEATURED_MESSAGES['notFound']);
    }

    public function test_an_offer_decided_already_says_so_and_a_refusal_says_whose_it_is(): void
    {
        $this->supabase(['POST /rpc/decide_game_download_offer' => self::reply(false)]);
        $this->signedIn()->from('/moderation/games')->post('/moderation/games/offer', ['offer' => '5', 'accept' => 'true'])
            ->assertSessionHas('toggle.message', GameDecisions::OFFER_MESSAGES['gone']);

        $this->supabase(['POST /rpc/decide_game_download_offer' => self::error('42501', 403)]);
        $this->signedIn()->from('/moderation/games')->post('/moderation/games/offer', ['offer' => '5', 'accept' => 'true'])
            ->assertSessionHas('toggle.message', GameDecisions::OFFER_MESSAGES['notAllowed']);

        $this->signedIn()->from('/moderation/games')->post('/moderation/games/offer', ['offer' => 'x'])
            ->assertSessionHas('toggle.message', GameDecisions::OFFER_MESSAGES['notSent']);
    }
}

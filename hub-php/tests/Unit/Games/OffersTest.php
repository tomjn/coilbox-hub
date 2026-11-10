<?php

namespace Tests\Unit\Games;

use App\Domain\Games\Offers;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `lib/games/offers.test.ts` only covers `decideDownloadOffer`, which writes
 * and is ported with the editing pages. These cover the queue read.
 */
class OffersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk', 'hub.supabase.secret_key' => 'sk']);
    }

    public function test_the_queue_is_the_held_offers_oldest_first_with_the_games_shortname(): void
    {
        Http::fake(['*/rest/v1/game_download_offer*' => Http::response([
            ['id' => 3, 'kind' => 'rapid', 'value' => 'ba:stable', 'asset' => null, 'filename' => null, 'created_at' => '2026-01-01T00:00:00Z', 'game' => ['shortname' => 'BA']],
            ['id' => 4, 'kind' => 'github', 'value' => 'a/b', 'asset' => 'b', 'filename' => 'b.sdz', 'created_at' => '2026-01-02T00:00:00Z', 'game' => null],
        ])]);

        $offers = Offers::fetchDownloadOffers(Supabase::admin());

        $this->assertSame([
            ['id' => 3, 'shortname' => 'BA', 'kind' => 'rapid', 'value' => 'ba:stable', 'asset' => null, 'filename' => null, 'created_at' => '2026-01-01T00:00:00Z'],
            ['id' => 4, 'shortname' => '', 'kind' => 'github', 'value' => 'a/b', 'asset' => 'b', 'filename' => 'b.sdz', 'created_at' => '2026-01-02T00:00:00Z'],
        ], $offers);
        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());

            return str_contains($url, 'select=id,kind,value,asset,filename,created_at,game(shortname)')
                && str_contains($url, 'state=eq.held')
                && str_contains($url, 'order=created_at.asc')
                && ! str_contains($url, 'game_id=');
        });
    }

    public function test_a_game_id_narrows_the_queue_to_that_game(): void
    {
        Http::fake(['*/rest/v1/game_download_offer*' => Http::response([])]);

        $this->assertSame([], Offers::fetchDownloadOffers(Supabase::admin(), 'g-1'));
        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'game_id=eq.g-1'));
    }

    public function test_an_unreadable_queue_is_empty_rather_than_an_error(): void
    {
        Http::fake(['*/rest/v1/game_download_offer*' => Http::response(['message' => 'boom'], 500)]);

        $this->assertSame([], Offers::fetchDownloadOffers(Supabase::admin()));
    }
}

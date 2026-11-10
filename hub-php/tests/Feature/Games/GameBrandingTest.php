<?php

namespace Tests\Feature\Games;

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `app/api/v1/games/branding/route.test.ts`: who may send a game's art. The
 * visitor's client answers `is_moderator()` and filters the game row the way
 * row level security would. The secret key client records what reached the
 * bucket and the row.
 */
class GameBrandingTest extends TestCase
{
    private const OWNER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    private const STRANGER = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    private const MODERATOR = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

    /** 2x2, 8 bit RGB, the same frozen encoder output the Next test uses. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAAEklEQVQImWMQqTghUnGCAUIBACJuBVHwJsa1AAAAAElFTkSuQmCC';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'pk',
            'hub.supabase.service_role_key' => 'sk',
            'cache.default' => 'array',
        ]);
    }

    private function fakeAs(string $visitor, ?array $heldRow = null): void
    {
        Http::fake(function (Request $request) use ($visitor, $heldRow) {
            $url = $request->url();
            $admin = ($request->header('apikey')[0] ?? null) === 'sk';
            if (str_ends_with($url, '/auth/v1/user')) {
                return Http::response(['id' => $visitor]);
            }
            if (str_ends_with($url, '/rpc/is_moderator')) {
                return Http::response($visitor === self::MODERATOR ? 'true' : 'false');
            }
            if (str_contains($url, '/storage/v1/object/')) {
                return Http::response(['Key' => 'x']);
            }
            if (str_contains($url, '/rest/v1/game') && $request->method() === 'PATCH') {
                return Http::response('', 204);
            }
            if (str_contains($url, '/rest/v1/game') && $admin) {
                return Http::response($heldRow === null ? [] : [$heldRow]);
            }
            if (str_contains($url, '/rest/v1/game')) {
                $query = urldecode((string) parse_url($url, PHP_URL_QUERY));
                $ownerOk = ! str_contains($query, 'owner_user_id') || str_contains($query, 'owner_user_id=eq.'.self::OWNER);
                $found = str_contains($query, 'shortname=eq.BA') && $ownerOk;

                return Http::response($found ? [['id' => 'game-ba']] : []);
            }

            return Http::response([], 404);
        });
    }

    private function send(string $kind = 'logo', ?string $bytes = null)
    {
        $file = UploadedFile::fake()->createWithContent($kind.'.png', $bytes ?? base64_decode(self::PNG));

        return $this->post('/api/v1/games/branding', ['shortname' => 'BA', 'kind' => $kind, 'file' => $file], ['Authorization' => 'Bearer token', 'Accept' => 'application/json']);
    }

    private function assertNothingWritten(): void
    {
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/storage/') || $r->method() === 'PATCH');
    }

    public function test_an_account_that_is_neither_owner_nor_moderator_is_refused_and_writes_nothing(): void
    {
        $this->fakeAs(self::STRANGER);

        $this->send()->assertForbidden()->assertJsonPath('error', "Only the game's owner or a moderator may send its art.");
        $this->assertNothingWritten();
    }

    public function test_a_moderator_who_does_not_own_the_game_stores_its_logo_in_the_bucket(): void
    {
        $this->fakeAs(self::MODERATOR);

        $this->send()->assertOk()->assertJsonPath('outcome', 'stored')->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/storage/v1/object/staged-pictures/games/BA/logo.png'));
        Http::assertSent(function (Request $r) {
            $body = json_decode($r->body(), true);

            return $r->method() === 'PATCH'
                && $body['logo_path'] === 'games/BA/logo.png'
                && $body['logo_staged_tier'] === 'bucket'
                && $body['logo_hash'] === hash('sha256', base64_decode(self::PNG));
        });
    }

    public function test_the_owner_stores_their_own_games_logo(): void
    {
        $this->fakeAs(self::OWNER);

        $this->send()->assertOk();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/storage/'));
    }

    public function test_card_art_writes_the_card_columns(): void
    {
        $this->fakeAs(self::OWNER);

        $this->send('card')->assertOk()->assertJsonPath('kind', 'card');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && array_keys(json_decode($r->body(), true)) === ['card_path', 'card_hash', 'card_staged_tier']);
    }

    public function test_the_same_bytes_again_write_nothing(): void
    {
        $this->fakeAs(self::OWNER, ['logo_path' => 'games/BA/logo.png', 'logo_hash' => hash('sha256', base64_decode(self::PNG)), 'logo_staged_tier' => null]);

        $this->send()->assertOk()->assertJsonPath('outcome', 'unchanged');
        $this->assertNothingWritten();
    }

    public function test_bytes_that_are_not_a_picture_are_refused_before_any_write(): void
    {
        $this->fakeAs(self::OWNER);

        $this->send('logo', 'not a png')->assertStatus(400)->assertJsonPath('error', 'The "file" part must be a PNG or WebP the hub can measure.');
        $this->assertNothingWritten();
    }

    public function test_bad_fields_are_refused(): void
    {
        $this->fakeAs(self::OWNER);

        $this->send('poster')->assertStatus(400)->assertJsonPath('error', 'The "kind" part must be one of logo, banner, card.');
        $this->post('/api/v1/games/branding', ['shortname' => 'BA', 'kind' => 'logo'], ['Authorization' => 'Bearer t'])
            ->assertStatus(400)->assertJsonPath('error', 'The "file" part is required and must carry the encoded bytes.');
        $this->call('POST', '/api/v1/games/branding', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer t', 'CONTENT_TYPE' => 'application/json'], '{}')
            ->assertStatus(415);
    }

    public function test_a_picture_past_the_cap_is_a_413(): void
    {
        $this->fakeAs(self::OWNER);

        $this->send('logo', str_repeat('x', 512 * 1024 + 1))->assertStatus(413);
    }

    public function test_no_token_is_a_401(): void
    {
        Http::fake();

        $this->post('/api/v1/games/branding', ['shortname' => 'BA', 'kind' => 'logo'])->assertUnauthorized();
        Http::assertNothingSent();
    }
}

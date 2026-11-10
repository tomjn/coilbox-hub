<?php

namespace Tests\Feature;

use App\Domain\Container\Container;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `/publish`, `/item/<id>/edit` and withdrawing, against a faked Supabase. */
class PublishTest extends TestCase
{
    private const URL = 'https://sb.test';

    private const ID = '11111111-1111-4111-8111-111111111111';

    private const USER = 'user-1';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => self::URL,
            'hub.supabase.publishable_key' => 'pk',
            'hub.supabase.service_role_key' => 'service-key',
            'app.url' => 'https://hub.test',
        ]);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function signedIn(): static
    {
        return $this->withSession([
            'supabase' => ['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600, 'user_id' => self::USER, 'aal' => 'aal1'],
            'nav' => ['id' => self::USER, 'name' => 'Tom', 'moderation' => 'none'],
        ]);
    }

    private function presetCode(): string
    {
        return Container::encodeCode('preset', Container::SUPPORTED_KIND_VERSIONS['preset'], (object) [
            'gameName' => 'Beyond All Reason',
            'mapName' => 'Comet Catcher Remake',
            'startPosType' => 2,
            'modOptionValues' => new \stdClass,
            'participants' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function editable(string $author = self::USER, ?string $deletedAt = null): array
    {
        return ['id' => self::ID, 'title' => 'Old', 'description' => 'Words', 'tags' => ['eco', '8v8'], 'author_id' => $author, 'deleted_at' => $deletedAt];
    }

    /** The edit page's own read answers with the row, the public read for the title with nothing. */
    private function fakeEditRead(array $row): void
    {
        Http::fake(fn (Request $r) => str_contains(urldecode($r->url()), 'deleted_at') ? Http::response([$row]) : Http::response([]));
    }

    public function test_a_signed_out_visitor_sees_the_sign_in_panel(): void
    {
        Http::fake();

        $this->get('/publish')
            ->assertOk()
            ->assertSee('Signing in is only needed to publish')
            ->assertSee('Sign in with Discord')
            ->assertSee('<input type="hidden" name="next" value="/publish">', false)
            ->assertDontSee('name="code"', false);
    }

    public function test_a_signed_in_visitor_sees_the_form_and_their_name(): void
    {
        Http::fake();

        $this->signedIn()->get('/publish')
            ->assertOk()
            ->assertSee('Publishing as')
            ->assertSee('Tom')
            ->assertSee('name="code"', false)
            ->assertDontSee('Sign in with Discord');
    }

    public function test_publishing_asks_the_auth_server_and_refuses_without_a_user(): void
    {
        Http::fake([self::URL.'/auth/v1/user' => Http::response(['message' => 'bad jwt'], 401)]);

        $this->signedIn()->post('/publish', ['code' => $this->presetCode(), 'title' => 'Mine'])
            ->assertRedirect('/publish')
            ->assertSessionHas('publish.error', 'Sign in before publishing.')
            ->assertSessionHas('publish.values.title', 'Mine');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/rest/v1/item'));
    }

    public function test_a_bad_code_comes_back_with_what_was_typed(): void
    {
        Http::fake([self::URL.'/auth/v1/user' => Http::response(['id' => self::USER])]);

        $this->signedIn()->post('/publish', ['code' => 'nonsense', 'title' => 'Mine', 'description' => 'A long description', 'tags' => 'eco'])
            ->assertRedirect('/publish')
            ->assertSessionHas('publish.values.description', 'A long description');
        $this->assertStringContainsString('not something coilbox made', session('publish.error'));

        $this->get('/publish')->assertSee('A long description')->assertSee('role="alert"', false);
    }

    public function test_publishing_inserts_as_the_user_and_shows_the_share_link(): void
    {
        Http::fake([
            self::URL.'/auth/v1/user' => Http::response(['id' => self::USER]),
            self::URL.'/rest/v1/item*' => Http::response([['id' => self::ID, 'kind' => 'preset', 'title' => 'Mine']], 201),
        ]);

        $this->signedIn()->post('/publish', ['code' => $this->presetCode(), 'title' => 'Mine', 'tags' => ' Eco, ,8V8 '])
            ->assertRedirect('/publish')
            ->assertSessionHas('publish.publishedId', self::ID)
            ->assertSessionHas('publish.shareUrl', 'https://hub.test/i/'.self::ID);

        $insert = Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/rest/v1/item'))->first()[0];
        $this->assertSame(self::USER, $insert->data()['author_id']);
        $this->assertSame(['eco', '8v8'], $insert->data()['tags']);
        $this->assertSame('Bearer access', $insert->header('Authorization')[0]);

        $this->get('/publish')->assertSee('https://hub.test/i/'.self::ID)->assertSee('Publish another');
    }

    public function test_the_edit_page_sends_a_signed_out_visitor_to_publish(): void
    {
        Http::fake();

        $this->get('/item/'.self::ID.'/edit')->assertRedirect('/publish');
    }

    public function test_the_edit_page_is_not_found_for_somebody_elses_item(): void
    {
        $this->fakeEditRead($this->editable('someone-else'));

        $this->signedIn()->get('/item/'.self::ID.'/edit')->assertNotFound();
    }

    public function test_the_edit_page_shows_the_words_and_a_withdraw_button(): void
    {
        $this->fakeEditRead($this->editable());

        $this->signedIn()->get('/item/'.self::ID.'/edit')
            ->assertOk()
            ->assertSee('value="Old"', false)
            ->assertSee('value="eco, 8v8"', false)
            ->assertSee('Withdraw it')
            ->assertSee('<input type="hidden" name="withdrawn" value="true">', false);
    }

    public function test_a_withdrawn_item_offers_to_put_it_back(): void
    {
        $this->fakeEditRead($this->editable(deletedAt: '2026-11-01T00:00:00Z'));

        $this->signedIn()->get('/item/'.self::ID.'/edit')
            ->assertOk()
            ->assertSee('Put it back')
            ->assertSee('href="/item/'.self::ID.'/withdrawn"', false);
    }

    public function test_saving_without_a_title_is_refused_before_any_write(): void
    {
        Http::fake();

        $this->signedIn()->post('/item/'.self::ID.'/edit', ['title' => '  '])
            ->assertRedirect('/item/'.self::ID.'/edit')
            ->assertSessionHas('edit.error', 'It needs a title.');
        Http::assertNothingSent();
    }

    public function test_saving_updates_the_words_and_goes_to_the_item(): void
    {
        Http::fake([self::URL.'/rest/v1/item*' => Http::response(null, 204)]);

        $this->signedIn()->post('/item/'.self::ID.'/edit', ['title' => ' New ', 'description' => ' More ', 'tags' => 'A,b,,c,d,e,f,g,h,i'])
            ->assertRedirect('/item/'.self::ID);

        $patch = Http::recorded(fn (Request $r) => $r->method() === 'PATCH')->first()[0];
        $this->assertStringContainsString('id=eq.'.self::ID, $patch->url());
        $this->assertSame(['title' => 'New', 'description' => 'More', 'tags' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']], $patch->data());
    }

    public function test_a_failed_save_says_why(): void
    {
        Http::fake([self::URL.'/rest/v1/item*' => Http::response(['message' => 'permission denied for column container', 'code' => '42501'], 403)]);

        $this->signedIn()->post('/item/'.self::ID.'/edit', ['title' => 'New'])
            ->assertRedirect('/item/'.self::ID.'/edit');
        $this->assertStringStartsWith('Could not save it: ', session('edit.error'));
    }

    public function test_withdrawing_sets_deleted_at_and_goes_to_the_withdrawn_page(): void
    {
        Http::fake([self::URL.'/rest/v1/item*' => Http::response(null, 204)]);

        $this->signedIn()->post('/item/'.self::ID.'/withdraw', ['withdrawn' => 'true'])
            ->assertRedirect('/item/'.self::ID.'/withdrawn');

        $patch = Http::recorded(fn (Request $r) => $r->method() === 'PATCH')->first()[0];
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $patch->data()['deleted_at']);
    }

    public function test_putting_it_back_clears_deleted_at(): void
    {
        Http::fake([self::URL.'/rest/v1/item*' => Http::response(null, 204)]);

        $this->signedIn()->post('/item/'.self::ID.'/withdraw', ['withdrawn' => 'false'])
            ->assertRedirect('/item/'.self::ID);

        $patch = Http::recorded(fn (Request $r) => $r->method() === 'PATCH')->first()[0];
        $this->assertSame(['deleted_at' => null], $patch->data());
    }
}

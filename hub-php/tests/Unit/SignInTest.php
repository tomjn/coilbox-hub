<?php

namespace Tests\Unit;

use App\Auth\GoTrue;
use App\Http\Controllers\Auth\SignInController;
use Tests\TestCase;

class SignInTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://hub.example']);
    }

    public function test_next_takes_a_path_from_the_form(): void
    {
        $this->assertSame('/publish?x=1', SignInController::next('/publish?x=1', null));
    }

    public function test_next_refuses_another_origin_from_the_form(): void
    {
        $this->assertSame('/', SignInController::next('//evil.example/x', null));
        $this->assertSame('/', SignInController::next('/\\evil.example', null));
        $this->assertSame('/', SignInController::next('https://evil.example/', null));
    }

    public function test_next_falls_back_to_a_same_site_referer(): void
    {
        $this->assertSame('/maps?author=a', SignInController::next(null, 'https://hub.example/maps?author=a'));
    }

    public function test_next_ignores_a_referer_from_elsewhere(): void
    {
        $this->assertSame('/', SignInController::next(null, 'https://evil.example/maps'));
        $this->assertSame('/', SignInController::next(null, 'http://hub.example/maps'));
    }

    public function test_pkce_challenge_is_the_s256_of_the_verifier(): void
    {
        $pair = GoTrue::pkcePair();
        $expected = rtrim(strtr(base64_encode(hash('sha256', $pair['verifier'], true)), '+/', '-_'), '=');
        $this->assertSame($expected, $pair['challenge']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43,128}$/', $pair['verifier']);
    }

    public function test_only_enabled_providers_are_accepted(): void
    {
        $this->assertSame('discord', GoTrue::provider('discord'));
        $this->assertNull(GoTrue::provider('apple'));
        $this->assertNull(GoTrue::provider(null));
    }
}

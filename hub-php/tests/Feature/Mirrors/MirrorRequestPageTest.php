<?php

namespace Tests\Feature\Mirrors;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\Identity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Moderation\ModerationTestCase;

/** `/mirrors/new`, the page a mirror's setup links to. */
class MirrorRequestPageTest extends ModerationTestCase
{
    use RefreshDatabase;

    private string $secret;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->key = B64::encode(sodium_crypto_sign_publickey_from_secretkey($this->secret));
        $this->app->instance(Identity::class, MirrorRequestsTest::identity());
        $this->supabase([
            'GET /rest/v1/mirror' => self::reply([]),
            'identity.php' => MirrorRequestsTest::identityAnswer($this->secret),
        ]);
    }

    private function fields(array $overrides = []): array
    {
        return $overrides + ['id' => 'my-mirror', 'name' => 'My Mirror', 'url' => MirrorRequestsTest::URL, 'key' => $this->key];
    }

    public function test_a_signed_out_visitor_goes_to_sign_in_and_comes_back_to_the_same_link(): void
    {
        $link = '/mirrors/new?'.http_build_query($this->fields());

        $this->get($link)->assertRedirect('/sign-in?next='.rawurlencode($link));
        $this->post('/mirrors/new', $this->fields())->assertRedirect('/sign-in?next=%2Fmirrors%2Fnew');
        $this->assertSame(0, DB::table('mirror_request')->count());
    }

    public function test_the_link_fills_in_the_form(): void
    {
        $this->signedIn()->get('/mirrors/new?'.http_build_query($this->fields()))
            ->assertOk()
            ->assertSee('value="my-mirror"', false)
            ->assertSee('value="My Mirror"', false)
            ->assertSee('value="'.MirrorRequestsTest::URL.'"', false)
            ->assertSee('value="'.$this->key.'"', false);
    }

    public function test_values_are_escaped(): void
    {
        $name = '"><script>alert(1)</script>';

        $response = $this->signedIn()->get('/mirrors/new?'.http_build_query($this->fields(['name' => $name])).'&id[]=x');

        $response->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee(e($name), false);
    }

    public function test_sending_the_request_stores_it_and_says_so(): void
    {
        $this->signedIn()->post('/mirrors/new', $this->fields())->assertRedirect('/mirrors/new');

        $this->signedIn()->get('/mirrors/new')->assertSee('Your request was sent');
        $this->assertSame(self::MODERATOR, DB::table('mirror_request')->value('requested_by'));
    }

    public function test_a_refused_request_shows_why_and_keeps_the_values(): void
    {
        $this->signedIn()->post('/mirrors/new', $this->fields(['url' => 'http://mirror.example.org']))->assertRedirect('/mirrors/new');

        $this->signedIn()->get('/mirrors/new')
            ->assertSee(Identity::MESSAGES['bad_url'])
            ->assertSee('value="http://mirror.example.org"', false);
        $this->assertSame(0, DB::table('mirror_request')->count());
    }
}

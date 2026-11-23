<?php

namespace Tests\Feature\Points;

use App\Domain\Games\GameEdits;
use App\Domain\Points\CommunityPoints;
use App\Supabase\Supabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsGameCatalogue;
use Tests\TestCase;

/** The first text in a game or faction field earns 5 points, once per field across all accounts. */
class FirstTextPointsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsGameCatalogue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalCatalogue();
        $this->seedCatalogue();
        $this->fakeSupabaseRemainder(['https://sb.test/auth/v1/user' => Http::response(['id' => self::OWNER])]);
        Http::fake(['https://sb.test/*' => Http::response([])]);
    }

    private function details(string $userId, string $description): array
    {
        return GameEdits::editGameDetails(Supabase::forToken('token', $userId), $userId, 'BA', 'Balanced Annihilation', $description, [], []);
    }

    private function faction(string $userId, string $tagline, string $description, string $key = 'arm'): array
    {
        return GameEdits::editFactionText(Supabase::forToken('token', $userId), $userId, 'BA', $key, $tagline, $description);
    }

    public function test_filling_an_empty_description_gives_5(): void
    {
        $this->assertTrue($this->details(self::OWNER, 'Words')['ok']);

        $this->assertSame(5, CommunityPoints::total(self::OWNER));
        $this->assertSame(1, DB::table('community_point')->where('kind', 'first_text')->where('subject', 'game:'.self::GAME.':description')->count());
    }

    public function test_editing_a_filled_description_gives_nothing(): void
    {
        DB::table('game')->where('id', self::GAME)->update(['description' => 'Held']);

        $this->details(self::OWNER, 'Changed');

        $this->assertSame(0, DB::table('community_point')->count());
    }

    public function test_a_blank_description_gives_nothing(): void
    {
        $this->details(self::OWNER, '   ');

        $this->assertSame(0, DB::table('community_point')->count());
    }

    public function test_clearing_and_filling_again_gives_no_new_row(): void
    {
        $this->details(self::OWNER, 'Words');
        $this->details(self::OWNER, '');
        $this->details(self::OWNER, 'Words again');

        $this->assertSame(1, DB::table('community_point')->count());
        $this->assertSame(5, CommunityPoints::total(self::OWNER));
    }

    public function test_a_second_account_filling_a_cleared_description_gets_nothing(): void
    {
        $this->details(self::OWNER, 'Words');
        $this->details(self::OWNER, '');
        DB::table('game')->where('id', self::GAME)->update(['owner_user_id' => self::STRANGER]);
        $this->assertTrue($this->details(self::STRANGER, 'Mine')['ok']);

        $this->assertSame(0, CommunityPoints::total(self::STRANGER));
        $this->assertSame(1, DB::table('community_point')->count());
    }

    public function test_a_refused_edit_gives_nothing(): void
    {
        $this->assertFalse($this->details(self::STRANGER, 'Mine')['ok']);

        $this->assertSame(0, DB::table('community_point')->count());
    }

    public function test_a_faction_tagline_and_description_in_one_save_give_10(): void
    {
        $this->assertTrue($this->faction(self::OWNER, 'Tag', 'Words')['ok']);

        $this->assertSame(10, CommunityPoints::total(self::OWNER));
        $this->assertSame(1, DB::table('community_point')->where('subject', 'faction:'.self::GAME.':arm:tagline')->count());
        $this->assertSame(1, DB::table('community_point')->where('subject', 'faction:'.self::GAME.':arm:description')->count());
    }

    public function test_editing_a_filled_faction_field_gives_nothing_but_filling_the_other_gives_5(): void
    {
        $this->faction(self::OWNER, 'Tag', '');
        $this->faction(self::OWNER, 'Tag 2', 'Words');

        $this->assertSame(10, CommunityPoints::total(self::OWNER));
        $this->assertSame(2, DB::table('community_point')->count());
    }

    public function test_a_refused_faction_edit_gives_nothing(): void
    {
        $this->assertFalse($this->faction(self::STRANGER, 'Tag', 'Words')['ok']);
        $this->assertFalse($this->faction(self::OWNER, 'Tag', 'Words', 'nobody')['ok']);

        $this->assertSame(0, DB::table('community_point')->count());
    }

    public function test_a_long_faction_key_gets_a_hashed_subject_that_fits(): void
    {
        $key = str_repeat('k', 300);
        $subject = CommunityPoints::firstTextSubject(self::GAME, $key, 'tagline');

        $this->assertSame('faction:'.self::GAME.':'.sha1($key).':tagline', $subject);
        $this->assertLessThanOrEqual(255, strlen($subject));
        $this->assertSame('faction:'.self::GAME.':arm:tagline', CommunityPoints::firstTextSubject(self::GAME, 'arm', 'tagline'));
        $this->assertSame('game:'.self::GAME.':description', CommunityPoints::firstTextSubject(self::GAME, null, 'description'));
    }
}

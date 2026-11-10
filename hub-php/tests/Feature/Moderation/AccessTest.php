<?php

namespace Tests\Feature\Moderation;

use App\Domain\Moderation\Access;
use App\Http\Controllers\Auth\AccountController;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Who gets a moderation page, from `lib/access/secondFactor.test.ts` and the
 * stranger test in `lib/maps/moderation.test.ts`. The check is a line in each
 * route, so it is asserted against every one of them.
 */
class AccessTest extends ModerationTestCase
{
    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        return [
            'reports' => ['/moderation'],
            'pictures' => ['/moderation/assets'],
            'trail' => ['/moderation/trail'],
            'maps' => ['/moderation/maps'],
            'one map' => ['/moderation/maps/comet-catcher-1-8'],
            'map packs' => ['/moderation/map-packs'],
            'one pack' => ['/moderation/map-packs/9e0f0000-0000-4000-8000-000000000001'],
            'authors' => ['/moderation/authors'],
            'users' => ['/moderation/users'],
            'allowances' => ['/ops'],
        ];
    }

    public function test_the_four_answers_are_read_as_themselves_and_anything_else_is_none(): void
    {
        foreach (['granted', 'verify', 'enrol', 'none'] as $answer) {
            $this->assertSame($answer, Access::toCapabilityAccess($answer));
        }
        $this->assertSame('none', Access::toCapabilityAccess(true));
        $this->assertSame('none', Access::toCapabilityAccess(null));
        $this->assertSame('none', Access::toCapabilityAccess('GRANTED'));
    }

    public function test_the_step_up_page_is_told_where_to_send_them_back_to(): void
    {
        $this->assertSame('/account/verify?next=%2Fmoderation%2Fmaps%2Fcomet', AccountController::stepUpPath('/moderation/maps/comet'));
    }

    #[DataProvider('pages')]
    public function test_a_signed_in_stranger_gets_a_404_and_nothing_is_read_with_the_secret_key(string $path): void
    {
        $this->supabase(moderator: false, access: 'none');

        $this->signedIn()->get($path)->assertNotFound();
        foreach ($this->sent as $request) {
            $this->assertSame('Bearer access', $request->header('Authorization')[0] ?? null, 'a page asked something as the hub before deciding who is asking');
        }
    }

    #[DataProvider('pages')]
    public function test_a_signed_out_visitor_gets_a_404_without_a_question_asked(string $path): void
    {
        $this->supabase();

        $this->get($path)->assertNotFound();
        $this->assertSame([], $this->sent);
    }

    public function test_a_moderator_who_has_not_entered_a_code_is_sent_to_enter_one(): void
    {
        $this->supabase(moderator: false, access: 'verify');

        $this->signedIn()->get('/moderation/assets')->assertRedirect('/account/verify?next=%2Fmoderation%2Fassets');
    }

    public function test_a_moderator_required_to_enrol_is_sent_to_the_account_page(): void
    {
        $this->supabase(moderator: false, access: 'enrol');

        $this->signedIn()->get('/moderation')->assertRedirect('/account#second-factor');
    }

    public function test_an_error_from_the_database_is_a_404_not_a_redirect(): void
    {
        $this->supabase(['POST /rpc/capability_access' => self::error()], moderator: false);

        $this->signedIn()->get('/moderation')->assertNotFound();
    }

    public function test_granted_is_still_refused_once_is_moderator_has_said_no(): void
    {
        $this->supabase(moderator: false, access: 'granted');

        $this->signedIn()->get('/moderation')->assertNotFound();
    }

    public function test_the_step_up_path_keeps_a_slug_encoded(): void
    {
        $this->supabase(moderator: false, access: 'verify');

        $this->signedIn()->get('/moderation/maps/a%20b')->assertRedirect('/account/verify?next=%2Fmoderation%2Fmaps%2Fa%2520b');
    }

    public function test_the_games_queue_sends_a_stranger_to_the_reports_page(): void
    {
        $this->supabase(moderator: false);

        $this->signedIn()->get('/moderation/games')->assertRedirect('/moderation');
    }

    /** @return array<string, array{string, array<string, string>}> */
    public static function writeRoutes(): array
    {
        $id = '0f8fad5b-d9cb-469f-a165-70867728950e';

        return [
            'approve' => ['/moderation/assets/approve', ['asset' => $id]],
            'reject' => ['/moderation/assets/reject', ['asset' => $id, 'kind' => 'editorial']],
            'acknowledge' => ['/moderation/assets/acknowledge', ['conflict' => '7']],
            'return' => ['/moderation/trail/return', ['asset' => $id]],
            'clear facts' => ['/moderation/maps/clear', ['map' => $id]],
            'curated tags' => ['/moderation/maps/tags', ['map' => $id, 'slug' => 'comet', 'tags' => '1v1']],
            'merge' => ['/moderation/authors/merge', ['into' => 'a', 'key' => 'b']],
            'unmerge' => ['/moderation/authors/unmerge', ['from' => 'b']],
            'decide request' => ['/moderation/games/decide', ['request_id' => '1', 'approve' => 'true']],
        ];
    }

    /** Every write is a plain POST, so each asks for itself. */
    #[DataProvider('writeRoutes')]
    public function test_a_stranger_posting_by_hand_writes_nothing(string $path, array $form): void
    {
        $this->supabase(moderator: false);

        $this->signedIn()->post($path, $form)->assertRedirect();
        $this->assertSame([], $this->sentWrites());
    }
}

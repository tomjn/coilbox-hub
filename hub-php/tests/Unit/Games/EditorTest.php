<?php

namespace Tests\Unit\Games;

use App\Domain\Games\Editor;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Who may change a game, `lib/games/editor.test.ts`. The database side is
 * `supabase/tests/game_ownership.test.sql`. What is proved here is the question
 * every page and action asks before it shows a form or spends the secret key.
 */
class EditorTest extends TestCase
{
    private const OWNER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    private const STRANGER = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    private const MODERATOR = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

    private const GAMES = [
        ['id' => 'game-ba', 'shortname' => 'BA', 'owner_user_id' => self::OWNER],
        ['id' => 'game-zk', 'shortname' => 'ZK', 'owner_user_id' => null],
    ];

    /**
     * Supabase answering `is_moderator()` for one account and filtering the
     * game rows by the `eq` filters in the query, the way PostgREST would.
     */
    private function fake(string $userId, mixed $moderator = null): void
    {
        $moderator ??= Http::response(json_encode($userId === self::MODERATOR), 200, ['Content-Type' => 'application/json']);
        Http::fake(function (Request $request) use ($moderator) {
            if (str_contains($request->url(), '/rpc/is_moderator')) {
                return $moderator;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $rows = array_values(array_filter(self::GAMES, function (array $row) use ($query) {
                foreach (['shortname', 'owner_user_id'] as $column) {
                    if (isset($query[$column]) && 'eq.'.$row[$column] !== $query[$column]) {
                        return false;
                    }
                }

                return true;
            }));

            return Http::response(array_map(fn ($row) => ['id' => $row['id']], array_slice($rows, 0, 1)));
        });
    }

    private function ask(string $userId, string $shortname): ?array
    {
        return Editor::editableGame(Supabase::forToken('token'), $userId, $shortname);
    }

    public function test_the_owner_may_change_their_own_game(): void
    {
        $this->fake(self::OWNER);
        $this->assertSame(['id' => 'game-ba'], $this->ask(self::OWNER, 'BA'));
    }

    public function test_the_owner_may_not_change_a_game_they_do_not_own(): void
    {
        $this->fake(self::OWNER);
        $this->assertNull($this->ask(self::OWNER, 'ZK'));
    }

    public function test_a_moderator_may_change_a_game_somebody_else_owns(): void
    {
        $this->fake(self::MODERATOR);
        $this->assertSame(['id' => 'game-ba'], $this->ask(self::MODERATOR, 'BA'));
    }

    public function test_a_moderator_may_change_a_game_nobody_owns(): void
    {
        $this->fake(self::MODERATOR);
        $this->assertSame(['id' => 'game-zk'], $this->ask(self::MODERATOR, 'ZK'));
    }

    public function test_a_signed_in_account_that_is_neither_owner_nor_moderator_may_change_nothing(): void
    {
        $this->fake(self::STRANGER);
        $this->assertNull($this->ask(self::STRANGER, 'BA'));
        $this->assertNull($this->ask(self::STRANGER, 'ZK'));
    }

    public function test_a_moderator_asking_about_a_game_that_does_not_exist_gets_nothing(): void
    {
        $this->fake(self::MODERATOR);
        $this->assertNull($this->ask(self::MODERATOR, 'NOPE'));
    }

    public function test_an_is_moderator_call_that_errors_is_not_a_yes(): void
    {
        $this->fake(self::STRANGER, Http::response(['message' => 'boom'], 500));
        $this->assertNull($this->ask(self::STRANGER, 'BA'));
    }
}

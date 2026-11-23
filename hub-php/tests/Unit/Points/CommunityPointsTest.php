<?php

namespace Tests\Unit\Points;

use App\Domain\Points\CommunityPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class CommunityPointsTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'aaaaaaaa-0000-0000-0000-000000000001';

    private const B = 'bbbbbbbb-0000-0000-0000-000000000002';

    public function test_an_award_adds_the_kinds_points(): void
    {
        CommunityPoints::award(self::A, CommunityPoints::ITEM, 'i1');

        $this->assertSame(20, CommunityPoints::total(self::A));
    }

    public function test_awarding_twice_adds_one_row(): void
    {
        CommunityPoints::award(self::A, CommunityPoints::ITEM, 'i1');
        CommunityPoints::award(self::A, CommunityPoints::ITEM, 'i1');

        $this->assertSame(1, DB::table('community_point')->count());
        $this->assertSame(20, CommunityPoints::total(self::A));
    }

    public function test_forget_removes_only_that_accounts_rows(): void
    {
        CommunityPoints::award(self::A, CommunityPoints::ITEM, 'i1');
        CommunityPoints::award(self::A, CommunityPoints::MAP, 'm1');
        CommunityPoints::award(self::B, CommunityPoints::ITEM, 'i2');

        CommunityPoints::forget(self::A);

        $this->assertSame(0, CommunityPoints::total(self::A));
        $this->assertSame(20, CommunityPoints::total(self::B));
    }

    public function test_revoke_removes_every_holders_row(): void
    {
        CommunityPoints::award(self::A, CommunityPoints::GAME_OWNER, 'g1');
        CommunityPoints::award(self::B, CommunityPoints::GAME_OWNER, 'g1');

        CommunityPoints::revoke(CommunityPoints::GAME_OWNER, 'g1');

        $this->assertSame(0, CommunityPoints::total(self::A));
        $this->assertSame(0, CommunityPoints::total(self::B));
    }

    public function test_first_text_is_earned_once_across_accounts(): void
    {
        CommunityPoints::award(self::A, CommunityPoints::FIRST_TEXT, 'game:g1:description');
        CommunityPoints::award(self::B, CommunityPoints::FIRST_TEXT, 'game:g1:description');

        $this->assertSame(5, CommunityPoints::total(self::A));
        $this->assertSame(0, CommunityPoints::total(self::B));
    }

    public function test_a_null_user_earns_nothing(): void
    {
        CommunityPoints::award(null, CommunityPoints::ITEM, 'i1');

        $this->assertSame(0, DB::table('community_point')->count());
    }

    public function test_a_failed_write_does_not_throw(): void
    {
        // A mock instead of dropping the table, because DDL commits on MariaDB.
        DB::shouldReceive('table')->andThrow(new QueryException('sqlite', 'select 1', [], new \Exception('boom')));

        CommunityPoints::award(self::A, CommunityPoints::ITEM, 'i1');
        CommunityPoints::revoke(CommunityPoints::ITEM, 'i1');

        $this->addToAssertionCount(1);
    }

    public function test_an_unknown_kind_earns_nothing_and_does_not_throw(): void
    {
        CommunityPoints::award(self::A, 'nonsense', 'x1');

        $this->assertSame(0, DB::table('community_point')->count());
    }
}

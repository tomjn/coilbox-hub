<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Empties `community_point` inside the test's own transaction. On MariaDB the
 * schema is migrated once per run, and tests that fake Supabase without
 * RefreshDatabase still award points for real, so their rows are committed
 * and would otherwise be counted here. Use it after RefreshDatabase.
 */
trait StartsWithNoPoints
{
    protected function setUpStartsWithNoPoints(): void
    {
        DB::table('community_point')->delete();
    }
}

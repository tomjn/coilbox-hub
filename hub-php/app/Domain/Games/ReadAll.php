<?php

namespace App\Domain\Games;

use App\Supabase\Query;
use App\Supabase\SupabaseException;
use Closure;

/**
 * Every row a query answers with, however many requests that takes, as
 * `lib/supabase/readAll.ts`. PostgREST caps a response at 1000 rows, and a
 * game may carry 2000 units, so a single read would be silently short.
 *
 * The loop advances by what arrived and stops on the first empty page, so it
 * is right whatever the cap is. The caller must order the query and must not
 * call `range` itself. Null on a failed read.
 */
final class ReadAll
{
    public const PAGE = 1000;

    /** A safety rail on a server that never returns an empty page. */
    private const MAX_PAGES = 2000;

    /**
     * @param  Closure(): Query  $query  builds a fresh query for each page
     * @return list<array<string, mixed>>|null
     */
    public static function rows(Closure $query): ?array
    {
        $rows = [];
        $from = 0;
        for ($requests = 0; $requests < self::MAX_PAGES; $requests++) {
            try {
                $held = $query()->range($from, $from + self::PAGE - 1)->get();
            } catch (SupabaseException) {
                return null;
            }
            array_push($rows, ...$held);
            if ($held === []) {
                return $rows;
            }
            $from += count($held);
        }

        return $rows;
    }
}

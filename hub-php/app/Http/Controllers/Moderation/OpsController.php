<?php

namespace App\Http\Controllers\Moderation;

use App\Domain\Moderation\Access;
use App\Domain\Moderation\Meters;
use App\Domain\Moderation\Orphans;
use App\Domain\Moderation\Promotion;
use App\Supabase\Supabase;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * `/ops`, the allowances page, from `app/ops/page.tsx` (issue #113). Behind
 * `is_moderator()` because no other capability fits. The numbers are read
 * with the secret key, because they count rows browser roles cannot see.
 */
class OpsController extends ModerationController
{
    public function show(): View|RedirectResponse
    {
        if (Access::moderatorClient() === null) {
            return Access::refuse('/ops');
        }
        $admin = Supabase::admin();
        $now = new DateTimeImmutable;
        $report = Meters::fetch($admin, $now);
        $unclaimed = Orphans::unclaimed($admin, Orphans::CLEANUP_BATCH);
        $since = null;
        foreach ($unclaimed as $object) {
            if ($since === null || $object['created_at'] < $since) {
                $since = $object['created_at'];
            }
        }

        return view('moderation.ops', [
            'report' => $report,
            'promotion' => Promotion::status($admin, $now),
            'stuckDeletions' => count(Orphans::abandonedDeletions($admin, $now)),
            'swept' => ['objects' => count($unclaimed), 'bytes' => array_sum(array_column($unclaimed, 'bytes')), 'since' => $since],
            'measured' => array_values(array_filter($report['meters'], fn ($m) => $m['basis'] !== 'dashboard')),
            'dashboard' => array_values(array_filter($report['meters'], fn ($m) => $m['basis'] === 'dashboard')),
        ]);
    }
}

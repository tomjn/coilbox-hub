<?php

namespace App\Http\Controllers\Moderation;

use App\Auth\HubSession;
use App\Domain\Moderation\Access;
use App\Domain\Moderation\Meters;
use App\Domain\Points\CommunityPoints;
use App\Supabase\SupabaseException;
use App\Support\HubCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/moderation`, the report queue and what is featured, from
 * `app/moderation/page.tsx` and `actOnReport` in `app/moderation/actions.ts`.
 */
class ReportsController extends ModerationController
{
    public function index(): View|RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return Access::refuse('/moderation');
        }

        try {
            $reports = $supabase->from('report')
                ->select('id,item_id,map_id,kind,reason,created_at,item(id,title,kind,deleted_at),map(slug,map_name,display_name,map_version)')
                ->is('handled_at', null)->order('created_at', ascending: false)->limit(100)->get();
        } catch (SupabaseException) {
            $reports = [];
        }
        // Featuring happens on an item's own page. This is the way back to one.
        try {
            $featured = $supabase->from('item')->select('id,title,featured_at')
                ->not('featured_at', 'is', 'null')->order('featured_at', ascending: false)->get();
        } catch (SupabaseException) {
            $featured = [];
        }

        return view('moderation.reports', ['reports' => $reports, 'featured' => $featured]);
    }

    /**
     * Withdrawing and marking handled are one action, because a report left
     * open after acting on it only gets read again. A map report carries no
     * item, so marking it handled is all there is to do.
     */
    public function act(Request $request): RedirectResponse
    {
        // Row level security refuses a stranger too. This refuses them before
        // any write is sent, as every other moderation action does.
        $supabase = \App\Domain\Moderation\Access::moderatorClient();
        $userId = HubSession::userId();
        if ($supabase === null || $userId === null) {
            return $this->back('/moderation');
        }
        $id = (string) $request->input('report_id', '');
        $itemId = (string) $request->input('item_id', '');
        $now = Meters::iso(new \DateTimeImmutable);

        if ($itemId !== '' && $request->input('withdraw') === 'true') {
            try {
                $changed = $supabase->from('item')->update(['deleted_at' => $now])->eq('id', $itemId)->select('id')->get();
                if ($changed !== []) {
                    CommunityPoints::revoke(CommunityPoints::ITEM, $itemId);
                }
            } catch (SupabaseException $e) {
                report($e);
            }
        }
        // Withdrawing is upholding. Anything but an explicit upheld is a dismissal.
        $outcome = $request->input('withdraw') === 'true' || $request->input('outcome') === 'upheld' ? 'upheld' : 'dismissed';
        try {
            $handled = $supabase->from('report')->update(['handled_at' => $now, 'handled_by' => $userId, 'outcome' => $outcome])
                ->eq('id', $id)->select('id,reporter_id')->get();
            if ($outcome === 'upheld' && $handled !== []) {
                CommunityPoints::award($handled[0]['reporter_id'] ?? null, CommunityPoints::REPORT, $id);
            }
        } catch (SupabaseException $e) {
            report($e);
        }
        // A withdrawn item is held, and so is every listing it was on.
        if ($itemId !== '') {
            HubCache::invalidate([HubCache::item($itemId), HubCache::ITEMS]);
        }

        return redirect('/moderation');
    }
}

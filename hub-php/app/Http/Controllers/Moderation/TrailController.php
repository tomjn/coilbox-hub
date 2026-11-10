<?php

namespace App\Http\Controllers\Moderation;

use App\Domain\Moderation\Access;
use App\Domain\Moderation\Queue;
use App\Domain\Moderation\Trail;
use App\Supabase\Supabase;
use App\Support\HubCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/moderation/trail`, what has been done to the pictures and by whom, from
 * `app/moderation/trail` (issue #115). No thumbnails and no paths: every
 * picture is a link to `/moderation/assets/<id>`.
 */
class TrailController extends ModerationController
{
    public function index(Request $request): View|RedirectResponse
    {
        if (Access::moderatorClient() === null) {
            return Access::refuse('/moderation/trail');
        }
        $account = $request->query('account');
        $focus = Queue::isUuid($account) ? $account : null;
        $admin = Supabase::admin();
        $trail = $focus !== null ? Trail::account($admin, $focus) : null;

        return view('moderation.trail', [
            'focus' => $focus,
            'trail' => $trail,
            'recent' => $trail !== null ? [] : Trail::recent($admin),
        ]);
    }

    /**
     * Put an editorial rejection back in the queue. The database refuses a
     * safety rejection, for this button and a hand written post alike.
     */
    public function return(Request $request): RedirectResponse
    {
        $supabase = Access::moderatorClient();
        $ids = Queue::pictureIds((array) $request->input('asset', []));
        if ($supabase !== null && $ids !== []) {
            Queue::return($supabase, $ids[0]);
            // Back in the queue means not approved, so anything drawing it must stop.
            HubCache::invalidate([HubCache::ASSETS]);
        }

        return $this->back('/moderation/trail');
    }
}

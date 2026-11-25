<?php

namespace App\Http\Controllers\Moderation;

use App\Auth\HubSession;
use App\Domain\Engines\EngineUploaders;
use App\Domain\Moderation\Access;
use App\Domain\Moderation\Accounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** `/moderation/engines`, the people allowed to upload engine builds from Coilbox. */
class EngineUploadersController extends ModerationController
{
    public function index(Request $request): View|RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return Access::refuse('/moderation/engines');
        }
        $q = $request->query('q');
        $term = is_string($q) ? trim($q) : '';
        $found = $term === '' ? null : Accounts::fetch($supabase, $term, 0);

        $grants = EngineUploaders::all();
        $ids = array_values(array_unique(array_merge(
            array_column($grants, 'user_id'),
            array_column($grants, 'granted_by'),
        )));
        $names = $ids === [] ? [] : DB::table('profile')->whereIn('user_id', $ids)->pluck('display_name', 'user_id')->all();

        return view('moderation.engine-uploaders', [
            'grants' => $grants,
            'names' => $names,
            'term' => $term,
            'found' => $found,
            'granted' => array_flip(array_column($grants, 'user_id')),
        ]);
    }

    public function grant(Request $request): RedirectResponse
    {
        if (Access::moderatorClient() === null) {
            return $this->back('/moderation/engines');
        }
        $userId = trim((string) $request->input('user_id', ''));
        if (! Str::isUuid($userId)) {
            $state = ['ok' => false, 'message' => 'Pick an account to grant.'];
        } elseif (EngineUploaders::grant($userId, (string) HubSession::userId())) {
            $state = ['ok' => true, 'message' => 'That account can now upload engines.'];
        } else {
            $state = ['ok' => false, 'message' => 'That account is already granted.'];
        }

        return redirect()->back(fallback: '/moderation/engines')->with('engineUploadersState', $state);
    }

    public function revoke(Request $request): RedirectResponse
    {
        if (Access::moderatorClient() === null) {
            return $this->back('/moderation/engines');
        }
        $state = EngineUploaders::revoke(trim((string) $request->input('user_id', '')))
            ? ['ok' => true, 'message' => 'That account can no longer upload engines. Engines it already uploaded stay listed.']
            : ['ok' => false, 'message' => 'That account had no grant.'];

        return redirect()->back(fallback: '/moderation/engines')->with('engineUploadersState', $state);
    }
}

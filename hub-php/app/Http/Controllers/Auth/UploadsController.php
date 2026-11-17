<?php

namespace App\Http\Controllers\Auth;

use App\Auth\HubSession;
use App\Domain\Mirrors\Removal;
use App\Http\Controllers\Controller;
use App\Supabase\SupabaseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/account/uploads`, the maps and games a person uploaded through Coilbox,
 * and the uploads of every game they own, with a way to withdraw each.
 */
class UploadsController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $client = HubSession::client();
        if ($client === null || HubSession::userId() === null) {
            return redirect('/publish');
        }

        try {
            $archives = $client->rpc('my_archives');
            $error = ! is_array($archives);
        } catch (SupabaseException) {
            $archives = null;
            $error = true;
        }

        return view('auth.uploads', ['archives' => is_array($archives) ? $archives : [], 'error' => $error]);
    }

    public function withdraw(Request $request): RedirectResponse
    {
        $client = HubSession::client();
        if ($client === null || HubSession::userId() === null) {
            return redirect('/publish');
        }

        $state = Removal::remove($client, (string) $request->input('archive_id', ''), 'withdrawn', null);

        return redirect('/account/uploads')->with('uploadsState', $state);
    }
}

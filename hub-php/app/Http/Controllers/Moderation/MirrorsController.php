<?php

namespace App\Http\Controllers\Moderation;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\HubKeys;
use App\Domain\Mirrors\Registry;
use App\Domain\Moderation\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** `/moderation/mirrors`, the servers that hold map and game archives for the hub. */
class MirrorsController extends ModerationController
{
    public function index(): View|RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return Access::refuse('/moderation/mirrors');
        }
        $data = Registry::list($supabase);
        $keys = HubKeys::fromConfig();

        return view('moderation.mirrors', [
            'error' => $data === null,
            'mirrors' => $data['mirrors'] ?? [],
            'seq' => $data['seq'] ?? null,
            // What an operator puts in hub_keys. The next key too while one is being rotated in.
            'hubKey' => $keys === null ? null : B64::encode($keys->publicKey()),
            'nextHubKey' => $keys?->nextKey()['public_key'] ?? null,
        ]);
    }

    public function add(Request $request): RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return $this->back('/moderation/mirrors');
        }
        $state = Registry::add(
            $supabase,
            (string) $request->input('id', ''),
            (string) $request->input('name', ''),
            (string) $request->input('base_url', ''),
            (string) $request->input('public_key', ''),
        );
        $back = redirect()->back(fallback: '/moderation/mirrors')->with('mirrorsState', $state);

        return $state['ok'] ? $back : $back->withInput($request->except('_token'));
    }

    public function remove(Request $request): RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return $this->back('/moderation/mirrors');
        }
        $state = Registry::remove($supabase, (string) $request->input('mirror_id', ''));

        return redirect()->back(fallback: '/moderation/mirrors')->with('mirrorsState', $state);
    }

    public function enable(Request $request): RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return $this->back('/moderation/mirrors');
        }
        $state = Registry::enable($supabase, (string) $request->input('mirror_id', ''));

        return redirect()->back(fallback: '/moderation/mirrors')->with('mirrorsState', $state);
    }
}

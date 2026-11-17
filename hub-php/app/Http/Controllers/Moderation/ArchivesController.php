<?php

namespace App\Http\Controllers\Moderation;

use App\Domain\Mirrors\Removal;
use App\Domain\Moderation\Access;
use App\Supabase\SupabaseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/moderation/archives`, maps and games uploaded to hub mirrors: the ones
 * players reported as not matching their facts, then the newest uploads.
 */
class ArchivesController extends ModerationController
{
    public function index(Request $request): View|RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return Access::refuse('/moderation/archives');
        }
        $q = $request->query('q');
        $term = is_string($q) ? trim($q) : '';

        try {
            $data = $supabase->rpc('moderation_archives', ['p_query' => $term]);
        } catch (SupabaseException) {
            $data = null;
        }

        return view('moderation.archives', [
            'term' => $term,
            'error' => ! is_array($data),
            'reported' => is_array($data['reported'] ?? null) ? $data['reported'] : [],
            'recent' => is_array($data['recent'] ?? null) ? $data['recent'] : [],
        ]);
    }

    public function remove(Request $request): RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return $this->back('/moderation/archives');
        }
        $note = trim((string) $request->input('note', ''));
        $state = Removal::remove(
            $supabase,
            (string) $request->input('archive_id', ''),
            (string) $request->input('reason', ''),
            $note === '' ? null : $note,
        );

        return redirect()->back(fallback: '/moderation/archives')->with('archivesState', $state);
    }

    public function dismiss(Request $request): RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return $this->back('/moderation/archives');
        }
        $state = Removal::dismiss($supabase, (string) $request->input('archive_id', ''));

        return redirect()->back(fallback: '/moderation/archives')->with('archivesState', $state);
    }
}

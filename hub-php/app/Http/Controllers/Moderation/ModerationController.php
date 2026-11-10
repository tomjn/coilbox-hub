<?php

namespace App\Http\Controllers\Moderation;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * What the moderation controllers share: answering a form post the way
 * `useActionState` did. The answer is flashed under `toggle` with the key of
 * the form that sent it, so `<x-moderation.toggle-form>` shows it under that
 * form and no other. A refusal keeps what was typed.
 */
abstract class ModerationController extends Controller
{
    /**
     * The sections of the moderation bar, in reading order: the page the
     * header sends you to, then the queues, then the bill.
     */
    public const SECTIONS = [
        'reports' => ['label' => 'Reports', 'href' => '/moderation'],
        'pictures' => ['label' => 'Pictures', 'href' => '/moderation/assets'],
        'maps' => ['label' => 'Maps', 'href' => '/moderation/maps'],
        'mapPacks' => ['label' => 'Map packs', 'href' => '/moderation/map-packs'],
        'games' => ['label' => 'Games', 'href' => '/moderation/games'],
        'authors' => ['label' => 'Authors', 'href' => '/moderation/authors'],
        'users' => ['label' => 'Users', 'href' => '/moderation/users'],
        'allowances' => ['label' => 'Allowances', 'href' => '/ops'],
    ];

    /** @param array{ok: bool, message: string} $state */
    protected function answer(Request $request, array $state, string $fallback): RedirectResponse
    {
        $back = redirect()->back(fallback: $fallback)->with('toggle', [
            'form' => (string) $request->input('_form', ''),
            'ok' => $state['ok'],
            'message' => $state['message'],
        ]);

        return $state['ok'] ? $back : $back->withInput($request->except('_token'));
    }

    /** Back to the page the form was on, for actions that answer nothing. */
    protected function back(string $fallback): RedirectResponse
    {
        return redirect()->back(fallback: $fallback);
    }
}

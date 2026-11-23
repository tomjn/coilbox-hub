<?php

namespace App\Http\Controllers\Mirrors;

use App\Auth\HubSession;
use App\Domain\Mirrors\Identity;
use App\Domain\Mirrors\MirrorRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/mirrors/new`, where a signed in user asks for a mirror to be added. The
 * mirror's setup page links here with the mirror's details in the query.
 */
class RequestController extends Controller
{
    private const FIELDS = ['id', 'name', 'url', 'key'];

    public function show(Request $request): View|RedirectResponse
    {
        if (HubSession::userId() === null) {
            return redirect('/sign-in?next='.rawurlencode($request->getRequestUri()));
        }

        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = (string) old($field, self::text($request->query($field)));
        }

        return view('mirrors.new', ['values' => $values]);
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = HubSession::userId();
        if ($userId === null) {
            return redirect('/sign-in?next='.rawurlencode('/mirrors/new'));
        }

        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = trim(self::text($request->input($field)));
        }
        $state = MirrorRequests::submit($userId, $values['id'], $values['name'], $values['url'], $values['key'], app(Identity::class));
        $back = redirect('/mirrors/new')->with('mirrorRequestState', $state);

        return $state['ok'] ? $back : $back->withInput($values);
    }

    /** A query or form value as text, ignoring arrays a crafted link might send. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}

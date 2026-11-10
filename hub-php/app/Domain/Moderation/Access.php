<?php

namespace App\Domain\Moderation;

use App\Auth\HubSession;
use App\Http\Controllers\Auth\AccountController;
use App\Supabase\Client;
use App\Supabase\SupabaseException;
use Illuminate\Http\RedirectResponse;

/**
 * Who may open a moderation page, `lib/access/secondFactor.ts` in the Next app.
 *
 * The database is the authority. `is_moderator()` says yes or no for this
 * session, and `capability_access()` says why not: `granted`, `verify` (a
 * second factor exists and this session has not entered a code), `enrol`
 * (everybody must have one and this account has none) or `none`.
 */
final class Access
{
    public const ANSWERS = ['granted', 'verify', 'enrol', 'none'];

    /** Where an account sets up a second factor. */
    public const ENROL_PATH = '/account#second-factor';

    /** Anything the database did not say in so many words is `none`. */
    public static function toCapabilityAccess(mixed $value): string
    {
        return in_array($value, self::ANSWERS, true) ? $value : 'none';
    }

    /** Whether this client's session may moderate. An error is a no. */
    public static function isModerator(?Client $client): bool
    {
        if ($client === null) {
            return false;
        }
        try {
            return $client->rpc('is_moderator') === true;
        } catch (SupabaseException) {
            return false;
        }
    }

    /**
     * The signed in visitor's own client when they may moderate, otherwise
     * null. Every page and every action asks this first.
     */
    public static function moderatorClient(): ?Client
    {
        $client = HubSession::client();

        return self::isModerator($client) ? $client : null;
    }

    /** What the visitor may do with one capability. Signed out, or an error, is `none`. */
    public static function capabilityAccess(string $capability): string
    {
        $client = HubSession::client();
        if ($client === null) {
            return 'none';
        }
        try {
            return self::toCapabilityAccess($client->rpc('capability_access', ['capability' => $capability]));
        } catch (SupabaseException) {
            return 'none';
        }
    }

    /**
     * What a moderation page does once `is_moderator()` has said no. A
     * moderator who has not entered a code this session is sent to enter one,
     * and one who must enrol is sent to do that. Everybody else gets a 404,
     * because whether these pages exist is not something a stranger needs to
     * learn. `$next` is the page to come back to.
     */
    public static function refuse(string $next): RedirectResponse
    {
        $access = self::capabilityAccess('can_moderate');
        if ($access === 'verify') {
            return redirect(AccountController::stepUpPath($next));
        }
        if ($access === 'enrol') {
            return redirect(self::ENROL_PATH);
        }
        abort(404);
    }
}

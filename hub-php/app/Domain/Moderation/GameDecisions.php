<?php

namespace App\Domain\Moderation;

use App\Domain\Games\Editor;
use App\Supabase\Client;
use App\Supabase\SupabaseException;
use App\Support\HubCache;
use Illuminate\Support\Facades\Log;

/**
 * The writes `/moderation/games` makes, from `app/games/actions.ts`:
 * deciding an ownership request, hiding or showing a game or a release,
 * featuring a game, and deciding an offered download.
 *
 * The game edit page in the Next app shares these actions. When it is
 * ported it should call these rather than copy them.
 */
final class GameDecisions
{
    public const VISIBILITY_MESSAGES = [
        'signedOut' => 'You are signed out. Sign in, then try again.',
        'notAllowed' => "You can no longer change this. Only the game's owner or a moderator can hide or show it.",
        'notSaved' => 'That could not be saved. Try again in a few minutes.',
        'notSent' => 'The form did not reach the hub. Reload the page and try again.',
    ];

    /**
     * The confirmations a hide or show redirect carries, by key. The URL
     * carries the key and never the words, so nobody can hand a moderator a
     * link that puts their own words on the hub's page.
     */
    public const VISIBILITY_FLASH_MESSAGES = [
        'game-hidden' => 'Game hidden.',
        'game-shown' => 'Game shown again.',
        'release-hidden' => 'Release hidden.',
        'release-shown' => 'Release shown again.',
    ];

    public const FEATURED_MESSAGES = [
        'featured' => 'Game featured.',
        'unfeatured' => 'Game no longer featured.',
        'signedOut' => 'You are signed out. Sign in, then try again.',
        'notAllowed' => 'Only a moderator can feature a game.',
        'notFound' => 'No game with that shortname.',
        'notSaved' => 'That could not be saved. Try again in a few minutes.',
        'notSent' => 'The form did not reach the hub. Reload the page and try again.',
    ];

    public const OFFER_MESSAGES = [
        'accepted' => "Added to this game's downloads.",
        'declined' => 'Offer turned down.',
        'signedOut' => 'You are signed out. Sign in, then decide again.',
        'notAllowed' => "Only the game's owner or a moderator can decide an offered download.",
        'gone' => 'Somebody has already decided that one.',
        'notSaved' => 'That could not be saved. Try again in a few minutes.',
        'notSent' => 'The form did not reach the hub. Reload the page and try again.',
    ];

    /**
     * Approve or decline an ownership request. Approving moves ownership in
     * the same breath as the state, so an approved ask never sits beside an
     * unowned game. Silent either way, as in the Next app.
     */
    public static function decideRequest(Client $session, Client $admin, string $userId, string $id, bool $approve): void
    {
        if ($id === '' || ! Access::isModerator($session)) {
            return;
        }
        try {
            $request = $admin->from('game_ownership_request')->select('game_id,state,requested_by,game(shortname)')
                ->eq('id', (int) $id)->maybeSingle();
            if ($request === null || $request['state'] !== 'open') {
                return;
            }
            $admin->from('game_ownership_request')->update([
                'state' => $approve ? 'approved' : 'declined',
                'decided_by' => $userId,
                'decided_at' => Meters::iso(new \DateTimeImmutable),
            ])->eq('id', (int) $id)->execute();
        } catch (SupabaseException) {
            return;
        }
        if ($approve) {
            try {
                $admin->from('game')->update(['owner_user_id' => $request['requested_by']])->eq('id', $request['game_id'])->execute();
            } catch (SupabaseException) {
            }
        }
    }

    /**
     * Hide or show a game, as its owner or a moderator. `$flash` is the key
     * of the confirmation, set when the write landed.
     *
     * @return array{ok: bool, message: string, flash?: string}
     */
    public static function setGameVisibility(Client $session, Client $admin, string $userId, string $shortname, bool $hidden): array
    {
        if ($shortname === '') {
            return ['ok' => false, 'message' => self::VISIBILITY_MESSAGES['notSent']];
        }
        $game = Editor::editableGame($session, $userId, $shortname);
        if ($game === null) {
            return ['ok' => false, 'message' => self::VISIBILITY_MESSAGES['notAllowed']];
        }
        try {
            $admin->from('game')->update(self::who('hidden', $hidden, $userId))->eq('id', $game['id'])->execute();
        } catch (SupabaseException $e) {
            Log::error("setGameVisibility: {$shortname} was not updated", ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => self::VISIBILITY_MESSAGES['notSaved']];
        }
        HubCache::invalidate([HubCache::GAMES]);
        $flash = $hidden ? 'game-hidden' : 'game-shown';

        return ['ok' => true, 'message' => self::VISIBILITY_FLASH_MESSAGES[$flash], 'flash' => $flash];
    }

    /** @return array{ok: bool, message: string, flash?: string} */
    public static function setVersionVisibility(Client $session, Client $admin, string $userId, string $shortname, string $version, bool $hidden): array
    {
        $version = mb_substr($version, 0, 64);
        if ($shortname === '' || $version === '') {
            return ['ok' => false, 'message' => self::VISIBILITY_MESSAGES['notSent']];
        }
        $game = Editor::editableGame($session, $userId, $shortname);
        if ($game === null) {
            return ['ok' => false, 'message' => self::VISIBILITY_MESSAGES['notAllowed']];
        }
        try {
            $admin->from('game_version')->update(self::who('hidden', $hidden, $userId))
                ->eq('game_id', $game['id'])->eq('version', $version)->execute();
        } catch (SupabaseException $e) {
            Log::error("setVersionVisibility: {$shortname} {$version} was not updated", ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => self::VISIBILITY_MESSAGES['notSaved']];
        }
        HubCache::invalidate([HubCache::GAMES]);
        $flash = $hidden ? 'release-hidden' : 'release-shown';

        return ['ok' => true, 'message' => self::VISIBILITY_FLASH_MESSAGES[$flash], 'flash' => $flash];
    }

    /**
     * Put a game above the rule on the listing. Asks `is_moderator` and
     * nothing else, because an owner may hide their game but not feature it.
     *
     * @return array{ok: bool, message: string}
     */
    public static function setGameFeatured(Client $session, Client $admin, string $userId, string $shortname, bool $featured): array
    {
        $shortname = trim($shortname);
        if ($shortname === '') {
            return ['ok' => false, 'message' => self::FEATURED_MESSAGES['notSent']];
        }
        if (! Access::isModerator($session)) {
            return ['ok' => false, 'message' => self::FEATURED_MESSAGES['notAllowed']];
        }
        try {
            $rows = $admin->from('game')->update(self::who('featured', $featured, $userId))
                ->eq('shortname', $shortname)->select('shortname')->get();
        } catch (SupabaseException $e) {
            Log::error("setGameFeatured: {$shortname} was not updated", ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => self::FEATURED_MESSAGES['notSaved']];
        }
        // The form takes a shortname by hand, so a typo is the ordinary way here.
        if ($rows === []) {
            return ['ok' => false, 'message' => self::FEATURED_MESSAGES['notFound']];
        }
        HubCache::invalidate([HubCache::GAMES]);

        return ['ok' => true, 'message' => self::FEATURED_MESSAGES[$featured ? 'featured' : 'unfeatured']];
    }

    /**
     * Accept or turn down an offered download. The database decides whether
     * this caller may.
     *
     * @return array{ok: bool, message: string}
     */
    public static function decideOffer(Client $session, int $offerId, bool $accept): array
    {
        try {
            $decided = $session->rpc('decide_game_download_offer', ['p_offer_id' => $offerId, 'p_accept' => $accept]);
        } catch (SupabaseException $e) {
            if ($e->errorCode === '42501') {
                return ['ok' => false, 'message' => self::OFFER_MESSAGES['notAllowed']];
            }
            Log::error("decideDownloadOffer: offer {$offerId} was not decided", ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => self::OFFER_MESSAGES['notSaved']];
        }
        if ($decided !== true) {
            return ['ok' => false, 'message' => self::OFFER_MESSAGES['gone']];
        }
        if ($accept) {
            HubCache::invalidate([HubCache::GAMES]);
        }

        return ['ok' => true, 'message' => self::OFFER_MESSAGES[$accept ? 'accepted' : 'declined']];
    }

    /** An offer id off a form field, or null unless it is a positive whole number. */
    public static function offerId(mixed $value): ?int
    {
        return ApprovedConflicts::conflictId($value);
    }

    /**
     * Who and when ride one update, and clearing clears both, so a row never
     * carries a stale name.
     *
     * @return array<string, string|null>
     */
    public static function who(string $column, bool $on, string $userId): array
    {
        return $on
            ? ["{$column}_at" => Meters::iso(new \DateTimeImmutable), "{$column}_by" => $userId]
            : ["{$column}_at" => null, "{$column}_by" => null];
    }
}

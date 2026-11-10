<?php

namespace App\Domain\Games;

use App\Supabase\Client;
use App\Supabase\SupabaseException;
use Illuminate\Support\Facades\Log;

/**
 * The queue of download sources coilbox has offered and nobody has decided yet
 * (#408).
 *
 * `public.game_download_offer` grants `anon` and `authenticated` nothing and
 * carries no policy, so a browser session reads no offer at all, a
 * moderator's included. The read is made with the secret key, so the page asks
 * who is looking before it asks what is waiting (`editableGame` answers the
 * first question through row level security).
 *
 * Deciding an offer writes, and is ported with the editing pages.
 */
final class Offers
{
    /**
     * Every offer still waiting, oldest first, for one game or for all of them.
     *
     * Oldest first because that is the order somebody should work through them
     * in, and the same order the ownership queue reads. Each offer is
     * `['id', 'shortname', 'kind', 'value', 'asset', 'filename', 'created_at']`.
     * Empty when the read fails.
     *
     * @param  Client  $admin  the secret key client
     * @return list<array<string, mixed>>
     */
    public static function fetchDownloadOffers(Client $admin, ?string $gameId = null): array
    {
        $query = $admin
            ->from('game_download_offer')
            ->select('id,kind,value,asset,filename,created_at,game(shortname)')
            ->eq('state', 'held')
            ->order('created_at', ascending: true);
        if ($gameId !== null && $gameId !== '') {
            $query = $query->eq('game_id', $gameId);
        }

        try {
            $rows = $query->get();
        } catch (SupabaseException $error) {
            Log::error('fetchDownloadOffers: the queue could not be read', ['error' => $error->getMessage()]);

            return [];
        }

        return array_map(fn (array $row) => [
            'id' => $row['id'],
            'shortname' => $row['game']['shortname'] ?? '',
            'kind' => $row['kind'],
            'value' => $row['value'],
            'asset' => $row['asset'] ?? null,
            'filename' => $row['filename'] ?? null,
            'created_at' => $row['created_at'],
        ], $rows);
    }
}

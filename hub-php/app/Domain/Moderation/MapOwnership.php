<?php

namespace App\Domain\Moderation;

use App\Supabase\Client;
use App\Supabase\SupabaseException;
use App\Support\HubCache;
use Illuminate\Support\Facades\Log;

/**
 * Deciding a request to own a map or an author, from the ownership queue.
 * The game version is {@see GameDecisions::decideRequest()}, and this follows
 * it: the secret key writes, after the visitor's own client proved they are
 * a moderator.
 */
final class MapOwnership
{
    /**
     * Approving moves ownership with the state. An author request makes the
     * requester that author's only owner, replacing any owner there was.
     * Silent, as games are, except that this returns false when the request was
     * approved and ownership still did not move.
     */
    public static function decideRequest(Client $session, Client $admin, string $userId, string $id, bool $approve): bool
    {
        if ($id === '' || ! ctype_digit($id) || ! Access::isModerator($session)) {
            return true;
        }
        try {
            $request = $admin->from('map_ownership_request')->select('map_id,author_key,state,requested_by,map(slug)')
                ->eq('id', (int) $id)->maybeSingle();
            if ($request === null || $request['state'] !== 'open') {
                return true;
            }
            $admin->from('map_ownership_request')->update([
                'state' => $approve ? 'approved' : 'declined',
                'decided_by' => $userId,
                'decided_at' => Meters::iso(new \DateTimeImmutable),
            ])->eq('id', (int) $id)->execute();
        } catch (SupabaseException) {
            return true;
        }
        if (! $approve) {
            return true;
        }
        try {
            if ($request['map_id'] !== null) {
                $admin->from('map')->update(['owner_user_id' => $request['requested_by']])->eq('id', $request['map_id'])->execute();
                HubCache::invalidate([HubCache::map((string) ($request['map']['slug'] ?? ''))]);
            } else {
                // Delete then insert, not an upsert: the service role holds no update grant on author_owner,
                // and Postgres wants one for `on conflict do update` even when the row is new.
                $admin->from('author_owner')->delete()->eq('key', $request['author_key'])->execute();
                $admin->from('author_owner')->insert([['key' => $request['author_key'], 'user_id' => $request['requested_by']]])->execute();
                // The author's maps are not known here, and every page credited to them caches who owns them.
                HubCache::invalidate([HubCache::MAPS]);
            }
        } catch (SupabaseException $e) {
            Log::error("decideMapOwnership: request {$id} was approved but ownership did not move", ['error' => $e->getMessage()]);

            return false;
        }

        return true;
    }
}

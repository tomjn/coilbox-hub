<?php

namespace App\Http\Controllers\Moderation;

use App\Auth\HubSession;
use App\Domain\Moderation\Access;
use App\Domain\Moderation\Meters;
use App\Domain\Points\CommunityPoints;
use App\Domain\Replays\ReplayModeration;
use App\Supabase\SupabaseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Taking a shared replay down and hiding its chat (#671). Both forms are on
 * the replay's page for a moderator, and removing is also on each replay
 * report in the queue.
 */
class ReplaysController extends ModerationController
{
    /**
     * Removing a replay upholds every open report on it, and each reporter
     * earns their points as for any upheld report.
     */
    public function remove(Request $request): RedirectResponse
    {
        $supabase = Access::moderatorClient();
        $userId = HubSession::userId();
        if ($supabase === null || $userId === null) {
            return $this->back('/moderation');
        }
        $gameId = (string) $request->input('game_id', '');
        $reason = (string) $request->input('reason', '');
        if (! isset(ReplayModeration::REASONS[$reason])) {
            return $this->back('/moderation')->with('replayModeration', ['ok' => false, 'message' => 'Choose why the replay is coming down.']);
        }
        if (! ReplayModeration::remove($gameId, $reason, $userId)) {
            return $this->back('/moderation')->with('replayModeration', ['ok' => false, 'message' => 'That replay is not on the hub.']);
        }

        try {
            $handled = $supabase->from('report')
                ->update(['handled_at' => Meters::iso(new \DateTimeImmutable), 'handled_by' => $userId, 'outcome' => 'upheld'])
                ->eq('replay_id', $gameId)->is('handled_at', null)->select('id,reporter_id')->get();
            foreach ($handled as $row) {
                // A moderator does not earn points for upholding their own report.
                if ($row['reporter_id'] !== $userId) {
                    CommunityPoints::award($row['reporter_id'], CommunityPoints::REPORT, $row['id']);
                }
            }
        } catch (SupabaseException $e) {
            report($e);
        }

        return redirect('/moderation')->with('replayModeration', ['ok' => true, 'message' => 'The replay is down. Mirrors delete its files on their next sync.']);
    }

    /** Hides one message, named by `seq`, or all of the replay's chat when `seq` is empty. */
    public function hideChat(Request $request): RedirectResponse
    {
        $userId = HubSession::userId();
        if (Access::moderatorClient() === null || $userId === null) {
            return $this->back('/moderation');
        }
        $gameId = (string) $request->input('game_id', '');
        $seq = (string) $request->input('seq', '');
        if ($seq !== '' && ! ctype_digit($seq)) {
            return $this->back('/moderation');
        }
        ReplayModeration::hideChat($gameId, $seq === '' ? null : (int) $seq, $userId);

        return redirect('/replays/'.rawurlencode($gameId).'?tab=events&show=chat');
    }
}

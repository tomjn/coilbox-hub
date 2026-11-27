<?php

namespace App\Domain\Replays;

use App\Jobs\CheckReplayAnalysis;
use App\Supabase\Local\Values;
use App\Support\Bearer;
use Illuminate\Support\Facades\DB;

/**
 * Replay analyses sent from Coilbox (#664). Any signed-in user can send one
 * for a listed replay. {@see CheckReplayAnalysis} checks it against the
 * replay's final totals with {@see AnalysisLog}. A replay keeps one analysis,
 * the first to pass from the newest logger version. Later ones that pass from
 * that version agree with it.
 */
final class ReplayAnalyses
{
    private const GAME_ID = '/\A[0-9a-f]{32}\z/';

    /** @return array{status: int, body: array<string, mixed>} */
    public static function submit(Bearer $auth, string $gameId, string $raw): array
    {
        if (preg_match(self::GAME_ID, $gameId) !== 1) {
            return self::fail(400, 'The game id must be 32 lower case hex characters.', 'bad_request');
        }
        $json = json_decode($raw, true);
        if (! is_array($json) || ($json !== [] && array_is_list($json))) {
            return self::fail(400, 'Send the analysis as a JSON object.', 'bad_request');
        }
        $version = $json['logger_version'] ?? null;
        if (! is_int($version) || $version < 1) {
            return self::fail(400, 'logger_version must be the whole number Coilbox stamps on the run, at least 1.', 'bad_request');
        }
        $events = $json['events'] ?? null;
        if (! is_string($events) || trim($events) === '') {
            return self::fail(400, 'events must be the run\'s events.jsonl, as text.', 'bad_request');
        }
        if (DB::table('replay')->where('game_id', $gameId)->where('state', 'listed')->doesntExist()) {
            return self::fail(404, 'The hub has no listed replay with this game id.', 'not_listed');
        }

        $sha256 = hash('sha256', $events);
        $same = DB::table('replay_analysis_submission')->where('game_id', $gameId)->where('user_id', $auth->userId)
            ->where('sha256', $sha256)->where('logger_version', $version)->first();
        if ($same !== null) {
            return ['status' => 200, 'body' => ['analysis' => self::answer($same)]];
        }

        $id = DB::table('replay_analysis_submission')->insertGetId([
            'game_id' => $gameId, 'user_id' => $auth->userId, 'logger_version' => $version, 'sha256' => $sha256,
            'size' => strlen($events), 'events' => $events, 'state' => 'checking', 'sent_at' => Values::now(),
        ]);
        CheckReplayAnalysis::dispatch($id);

        return ['status' => 202, 'body' => ['analysis' => self::answer(DB::table('replay_analysis_submission')->where('id', $id)->first())]];
    }

    /**
     * Checks one analysis. Answers its new state, or null when it is not
     * waiting for a check.
     */
    public static function check(int $id): ?string
    {
        $row = DB::table('replay_analysis_submission')->where('id', $id)->where('state', 'checking')->first();
        if ($row === null) {
            return null;
        }
        $replay = DB::table('replay')->where('game_id', $row->game_id)->where('state', 'listed')->first();
        if ($replay === null) {
            return self::refuse($row, 'not_listed', 'The replay is no longer listed.');
        }
        $log = AnalysisLog::parse((string) $row->events);
        if (is_string($log)) {
            return self::refuse($row, 'unreadable', $log);
        }
        $teams = DB::table('replay_team')->where('game_id', $row->game_id)->orderBy('team')->get()->all();
        $difference = $log->difference($replay, $teams);
        if ($difference !== null) {
            return self::refuse($row, ...$difference);
        }

        return self::keep($row, $log, AnalysisFacts::of($log, $replay, $teams));
    }

    /** Refuses an analysis whose check failed on every try. */
    public static function failed(int $id, string $why): ?string
    {
        $row = DB::table('replay_analysis_submission')->where('id', $id)->where('state', 'checking')->first();

        return $row === null ? null : self::refuse($row, 'check_failed', 'The check did not finish: '.$why);
    }

    /**
     * How many people sent a run that passed for the analysis the replay
     * keeps, its sender included. Each person counts once however often they
     * send, as only separate runs say the log is a fair one.
     */
    public static function agreements(string $gameId): int
    {
        $version = DB::table('replay_analysis')->where('game_id', $gameId)->value('logger_version');
        if ($version === null) {
            return 0;
        }

        return DB::table('replay_analysis_submission')->where('game_id', $gameId)->where('logger_version', $version)
            ->whereIn('state', ['kept', 'agreed'])->distinct()->count('user_id');
    }

    /**
     * Works out the page's facts again from a kept analysis's log, for
     * analyses kept before they were stored. Answers false when the replay
     * has no kept analysis or its log no longer reads.
     */
    public static function writeFacts(string $gameId): bool
    {
        $kept = DB::table('replay_analysis')->where('game_id', $gameId)->first();
        $replay = DB::table('replay')->where('game_id', $gameId)->first();
        $log = $kept === null ? null : AnalysisLog::parse((string) $kept->events);
        if ($replay === null || ! $log instanceof AnalysisLog) {
            return false;
        }
        $teams = DB::table('replay_team')->where('game_id', $gameId)->orderBy('team')->get()->all();
        DB::table('replay_analysis')->where('game_id', $gameId)->where('sha256', $kept->sha256)
            ->update(['facts' => json_encode(AnalysisFacts::of($log, $replay, $teams), JSON_PRESERVE_ZERO_FRACTION)]);
        MapTotals::refresh($gameId);

        return true;
    }

    /** @param array<string, mixed> $facts */
    private static function keep(object $row, AnalysisLog $log, array $facts): string
    {
        return DB::transaction(function () use ($row, $log, $facts) {
            if (DB::table('replay_analysis_submission')->where('id', $row->id)->where('state', 'checking')->lockForUpdate()->doesntExist()) {
                return 'checked';
            }
            $kept = DB::table('replay_analysis')->where('game_id', $row->game_id)->lockForUpdate()->first();
            $version = (int) $row->logger_version;
            $state = match (true) {
                $kept === null || $version > (int) $kept->logger_version => 'kept',
                $version === (int) $kept->logger_version => 'agreed',
                default => 'older',
            };
            $now = Values::now();
            if ($state === 'kept') {
                if ($kept !== null) {
                    DB::table('replay_analysis_submission')->where('id', $kept->submission_id)->update(['state' => 'older']);
                }
                DB::table('replay_analysis')->upsert([[
                    'game_id' => $row->game_id, 'logger_version' => $version, 'submission_id' => $row->id, 'sha256' => $row->sha256,
                    'events' => $row->events, 'counts' => json_encode($log->counts()), 'facts' => json_encode($facts, JSON_PRESERVE_ZERO_FRACTION), 'kept_at' => $now,
                ]], ['game_id'], ['logger_version', 'submission_id', 'sha256', 'events', 'counts', 'facts', 'kept_at']);
                // Swaps the replay's share, so its deaths and damage are counted once.
                MapTotals::refresh($row->game_id);
            }
            DB::table('replay_analysis_submission')->where('id', $row->id)->update(['state' => $state, 'events' => null, 'checked_at' => $now]);

            return $state;
        });
    }

    private static function refuse(object $row, string $reason, string $detail): string
    {
        DB::table('replay_analysis_submission')->where('id', $row->id)->where('state', 'checking')->update([
            'state' => 'refused', 'reason' => $reason, 'detail' => mb_substr($detail, 0, 2000),
            'events' => null, 'checked_at' => Values::now(),
        ]);

        return $reason;
    }

    /** @return array<string, mixed> */
    private static function answer(object $row): array
    {
        return ['id' => (int) $row->id, 'game_id' => $row->game_id, 'logger_version' => (int) $row->logger_version,
            'state' => $row->state, 'reason' => $row->reason];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function fail(int $status, string $message, string $reason): array
    {
        return ['status' => $status, 'body' => ['error' => $message, 'reason' => $reason]];
    }
}

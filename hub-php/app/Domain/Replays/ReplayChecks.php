<?php

namespace App\Domain\Replays;

use App\Domain\Replays\Lua\Awards;
use App\Domain\Replays\Stream\ChatMessage;
use App\Domain\Replays\Stream\Event;
use App\Supabase\Local\Values;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Checks an uploaded replay against its file and keeps only what the file
 * confirms (#663). The file comes from the mirror it was uploaded to. A replay
 * that passes is stored and listed. One that fails is removed from the
 * mirrors, its game id is freed and the reason kept in `replay_refusal`.
 */
final class ReplayChecks
{
    /** Seconds to wait for the mirror, leaving the rest of the job's 80 for the check. */
    public const FETCH_TIMEOUT = 60;

    /**
     * Checks one replay. Answers `listed`, the refusal's reason, or null when
     * the replay is not waiting for a check.
     *
     * @throws RuntimeException when the mirror cannot give the file, so the job tries again
     */
    public static function check(string $gameId): ?string
    {
        $row = DB::table('replay')->where('game_id', $gameId)->where('state', 'checking')->first();
        if ($row === null) {
            return null;
        }
        $bytes = self::fetch($row);
        if (strlen($bytes) !== (int) $row->size || hash('sha256', $bytes) !== $row->sha256) {
            return self::refuse($row, 'wrong_file', 'The mirror gave a file of '.strlen($bytes).' bytes that is not the one reported.');
        }

        try {
            $replay = (new ReplayReader)->readBytes($bytes);
        } catch (ReplayException $e) {
            return self::refuse($row, 'unreadable', $e->getMessage());
        }
        unset($bytes);

        $summary = json_decode((string) $row->summary, true);
        $refusal = self::refusal($replay, $row, is_array($summary) ? $summary : []);
        if ($refusal !== null) {
            return self::refuse($row, ...$refusal);
        }

        return self::store($replay, $row, $summary) ? 'listed' : null;
    }

    /**
     * Refuses a replay whose check failed on every try, most often because the
     * mirror did not give the file. Its game id is freed for another sender.
     */
    public static function failed(string $gameId, string $why): ?string
    {
        $row = DB::table('replay')->where('game_id', $gameId)->where('state', 'checking')->first();

        return $row === null ? null : self::refuse($row, 'check_failed', 'The check did not finish: '.$why);
    }

    /** @return array{0: string, 1: string}|null the reason and detail */
    private static function refusal(Replay $replay, object $row, array $summary): ?array
    {
        if ($replay->header->gameId !== $row->game_id) {
            return ['wrong_game_id', "The file's game id is {$replay->header->gameId}, not the one reserved."];
        }
        if ($replay->stream->eventsOf(Event::GAME_OVER) === [] || $replay->header->gameTime === 0) {
            return ['no_game_over', 'The file has no game over.'];
        }
        if ($replay->endOfGame === null) {
            return ['no_end_of_game', 'The file has no end of game block.'];
        }
        if (ReplaySummary::parse($summary) !== $summary) {
            return ['summary_mismatch', 'The summary kept for this replay is not one the hub reads.'];
        }
        $difference = ReplayCheck::difference($replay, $summary);
        if ($difference !== null) {
            return ['summary_mismatch', $difference];
        }
        $uploader = DB::table('replay_sender')->where('game_id', $row->game_id)->where('user_id', $row->uploader)->value('name');
        if ($uploader !== null && ! in_array($uploader, ReplayCheck::names($replay), true)) {
            return ['sender_not_in_replay', "The sender's name \"{$uploader}\" is not a player or spectator in the file."];
        }

        return null;
    }

    private static function fetch(object $row): string
    {
        $base = DB::table('mirror')->where('id', $row->upload_mirror_id)->value('base_url');
        if ($base === null) {
            throw new RuntimeException("The mirror {$row->upload_mirror_id} is not in the hub's list.");
        }
        $size = (int) $row->size;
        $response = Http::timeout(self::FETCH_TIMEOUT)->withOptions([
            // Stop reading at the size the upload gave, so a wrong file cannot fill memory.
            'progress' => function ($total, $downloaded) use ($size) {
                if ($total > $size || $downloaded > $size) {
                    throw new RuntimeException('The mirror is sending more than the replay\'s '.$size.' bytes.');
                }
            },
        ])->get(rtrim((string) $base, '/').'/replays/'.$row->game_id.'.sdfz');
        if (! $response->successful()) {
            throw new RuntimeException("The mirror answered {$response->status()} for the replay file.");
        }

        return $response->body();
    }

    /** @return string the reason */
    private static function refuse(object $row, string $reason, string $detail): string
    {
        DB::transaction(function () use ($row, $reason, $detail) {
            if (! ReplayListing::remove($row->game_id, 'refused')) {
                return;
            }
            DB::table('replay_refusal')->insert([
                'game_id' => $row->game_id, 'sha256' => $row->sha256, 'uploader' => $row->uploader, 'reason' => $reason,
                'detail' => self::text($detail, 2000), 'summary' => $row->summary, 'refused_at' => Values::now(),
            ]);
            DB::table('replay')->where('game_id', $row->game_id)->delete();
        });

        return $reason;
    }

    /** Writes what the file confirmed and lists the replay. False when it stopped being checked meanwhile. */
    private static function store(Replay $replay, object $row, array $summary): bool
    {
        return DB::transaction(function () use ($replay, $row, $summary) {
            $gameId = $row->game_id;
            if (DB::table('replay')->where('game_id', $gameId)->where('state', 'checking')->lockForUpdate()->doesntExist()) {
                return false;
            }
            $script = $replay->script;
            $end = $replay->endOfGame;
            $winners = $end->winningAllyTeams;
            $names = ReplayCheck::names($replay);
            [$game, $version] = self::gameAndVersion($script->gameType);

            // A later sender's name was only checked against their own summary.
            DB::table('replay_sender')->where('game_id', $gameId)->where('user_id', '<>', $row->uploader)
                ->get(['user_id', 'name'])->reject(fn ($s) => in_array($s->name, $names, true))
                ->each(fn ($s) => DB::table('replay_sender')->where('game_id', $gameId)->where('user_id', $s->user_id)->delete());

            $awards = $replay->stream->luaMessagesOf('awards')[0]->value ?? null;
            DB::table('replay')->where('game_id', $gameId)->update([
                'game' => self::text($game), 'game_version' => $version === null ? null : self::text($version),
                'map' => self::text($script->map), 'engine' => self::text($replay->header->engineVersion),
                'started_at' => gmdate('Y-m-d H:i:s', $replay->header->startTime), 'duration' => $replay->header->gameTime,
                'winners' => json_encode($winners),
                'player_count' => count(array_filter($script->players, fn ($p) => ! $p->spectator)),
                'events' => self::json(array_map(fn (Event $e) => ['frame' => $e->frame, 'type' => $e->type, 'player' => $e->player, 'data' => $e->data], $replay->stream->events)),
                'extra' => self::json($awards instanceof Awards ? ['bar.awards' => array_map(
                    fn ($a) => ['name' => $a->name, 'team' => $a->team, 'value' => $a->value], $awards->awards)] : (object) []),
                'checked_at' => Values::now(),
            ]);

            $orders = [];
            foreach ($summary['build_orders'] as $entry) {
                $orders[$entry['team']] = $entry['orders'];
            }
            $starts = $replay->stream->startPositions();
            $teams = [];
            foreach ($script->teams as $id => $team) {
                $sample = $end->finalTotals($id);
                $totals = [];
                foreach (ReplaySummary::TOTALS as $key) {
                    $totals[$key] = $sample?->{lcfirst(str_replace('_', '', ucwords($key, '_')))};
                }
                $teams[] = ['game_id' => $gameId, 'team' => $id, 'ally_team' => $team->allyTeam,
                    'won' => in_array($team->allyTeam, $winners, true)] + $totals + [
                        'start_x' => $starts[$id]->x ?? null, 'start_z' => $starts[$id]->z ?? null,
                        'build_orders' => self::json($orders[$id] ?? []),
                    ];
            }
            DB::table('replay_team')->insert($teams);

            $seats = [];
            $played = PlayedFactions::of($replay);
            foreach ($script->players as $id => $p) {
                $figures = $p->spectator ? null : ($end->players[$id] ?? null);
                $faction = $p->spectator ? $p->side : $played[$id]['faction'];
                $seats[] = ['game_id' => $gameId, 'kind' => $p->spectator ? 'spectator' : 'player', 'number' => $id,
                    'name' => self::text($p->name), 'team' => $p->team, 'ally_team' => $p->allyTeam,
                    'faction' => $faction === null ? null : self::text($faction),
                    'won' => $p->spectator ? null : in_array($p->allyTeam, $winners, true),
                    'commands' => $figures?->commands, 'unit_commands' => $figures?->unitCommands, 'mouse_pixels' => $figures?->mousePixels,
                    'mouse_clicks' => $figures?->mouseClicks, 'key_presses' => $figures?->keyPresses];
            }
            foreach ($script->ais as $id => $a) {
                $seats[] = ['game_id' => $gameId, 'kind' => 'ai', 'number' => $id, 'name' => self::text($a->name),
                    'team' => $a->team, 'ally_team' => $a->allyTeam, 'faction' => $a->side === null ? null : self::text($a->side),
                    'won' => in_array($a->allyTeam, $winners, true),
                    'commands' => null, 'unit_commands' => null, 'mouse_pixels' => null, 'mouse_clicks' => null, 'key_presses' => null];
            }
            DB::table('replay_player')->insert($seats);

            $who = self::chatNames($replay);
            $chat = [];
            foreach ($replay->stream->chat as $m) {
                if ($m->audience === ChatMessage::PLAYER) {
                    continue;
                }
                $chat[] = ['game_id' => $gameId, 'seq' => count($chat), 'frame' => $m->frame, 'player' => $m->from,
                    'name' => self::text($who[$m->from] ?? ''), 'audience' => $m->audience, 'text' => self::text($m->text, 1000)];
            }
            foreach (array_chunk($chat, 500) as $chunk) {
                DB::table('replay_chat')->insert($chunk);
            }

            return ReplayListing::publish($gameId);
        });
    }

    /**
     * Splits the game type into the game and its release. A name the catalogue
     * has decides where, since either may hold spaces. Otherwise the release
     * is the last word.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function gameAndVersion(string $gameType): array
    {
        $splits = [];
        for ($at = strpos($gameType, ' '); $at !== false; $at = strpos($gameType, ' ', $at + 1)) {
            $splits[] = $at;
        }
        if ($splits === []) {
            return [$gameType, null];
        }
        $known = DB::table('game')->whereIn('display_name', array_map(fn ($at) => substr($gameType, 0, $at), $splits))->pluck('display_name')->all();
        $at = $splits[array_key_last($splits)];
        foreach ($splits as $split) {
            if (in_array(substr($gameType, 0, $split), $known, true)) {
                $at = $split;
            }
        }

        return [substr($gameType, 0, $at), substr($gameType, $at + 1)];
    }

    /** @return array<int, string> player names by number, as the match last gave them */
    private static function chatNames(Replay $replay): array
    {
        $names = array_map(fn ($p) => $p->name, $replay->script->players);
        foreach ($replay->stream->events as $e) {
            if ($e->type === Event::NEW_PLAYER || ($e->type === Event::PLAYER_NAME && ! isset($names[$e->player]))) {
                $names[$e->player] = $e->data['name'];
            }
        }

        return $names;
    }

    /** Text from the file as valid UTF-8 that fits its column. */
    private static function text(string $text, int $max = 255): string
    {
        return mb_substr(mb_scrub($text, 'UTF-8'), 0, $max);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}

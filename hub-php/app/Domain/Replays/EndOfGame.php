<?php

namespace App\Domain\Replays;

/**
 * The block the engine writes after the packet stream at game over: the
 * winning ally teams, five input figures per player and each team's samples
 * of 19 running totals.
 *
 * Beyond All Reason's autohosts keep one sample per team, taken at the last
 * frame, so those replays have final totals and no history.
 */
final readonly class EndOfGame
{
    /** `TeamStatistics` is an `i32` frame, 12 `f32` and 7 `i32`. */
    public const TEAM_SAMPLE_SIZE = 80;

    /** `PlayerStatistics` is five `i32`. */
    public const PLAYER_FIGURES_SIZE = 20;

    public function __construct(
        /** @var list<int> */
        public array $winningAllyTeams,
        /**
         * By player number. Empty when no team has a sample, because the engine
         * then leaves whatever was in memory in the player figures.
         *
         * @var array<int, PlayerFigures>
         */
        public array $players,
        /** @var array<int, list<TeamSample>> by team number, oldest first */
        public array $teams,
        public int $samplePeriod,
    ) {}

    /** The last sample of a team, which holds its final totals. */
    public function finalTotals(int $team): ?TeamSample
    {
        $samples = $this->teams[$team] ?? [];

        return $samples === [] ? null : $samples[array_key_last($samples)];
    }

    /**
     * Reads the block that starts at `$at`, which has to end exactly where the
     * file does.
     *
     * @throws ReplayException
     */
    public static function parse(string $bytes, int $at, Header $h): self
    {
        if ($h->playerStatElemSize < self::PLAYER_FIGURES_SIZE || $h->teamStatElemSize < self::TEAM_SAMPLE_SIZE) {
            throw new ReplayException('This replay keeps its end of game figures in a layout the hub does not read.');
        }
        if ($h->playerStatSize !== $h->numPlayers * $h->playerStatElemSize) {
            throw new ReplayException('This replay is damaged: its header gives a player figures size that does not fit its player count.');
        }

        $winners = array_values(unpack('C*', substr($bytes, $at, $h->winningAllyTeamsSize)) ?: []);
        $at += $h->winningAllyTeamsSize;

        $players = [];
        for ($p = 0; $p < $h->numPlayers; $p++) {
            $players[$p] = PlayerFigures::unpack($bytes, $at + $p * $h->playerStatElemSize);
        }
        $at += $h->playerStatSize;

        // Every team's sample count comes first, then the samples team by team.
        $counts = $h->numTeams === 0 ? [] : array_values(unpack('l'.$h->numTeams, $bytes, $at));
        $needed = 4 * $h->numTeams + array_sum($counts) * $h->teamStatElemSize;
        if (min([0, ...$counts]) < 0 || $needed !== $h->teamStatSize) {
            throw new ReplayException('This replay is damaged: its team sample counts do not add up to the size its header gives.');
        }
        $at += 4 * $h->numTeams;

        $teams = [];
        foreach ($counts as $team => $count) {
            $teams[$team] = [];
            for ($i = 0; $i < $count; $i++) {
                $teams[$team][] = TeamSample::unpack($bytes, $at);
                $at += $h->teamStatElemSize;
            }
        }

        return new self(
            winningAllyTeams: $winners,
            players: array_sum($counts) > 0 ? $players : [],
            teams: $teams,
            samplePeriod: $h->teamStatPeriod,
        );
    }
}

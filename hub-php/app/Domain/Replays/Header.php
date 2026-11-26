<?php

namespace App\Domain\Replays;

/**
 * The 352 byte version 5 header the engine writes at the start of a replay
 * (`DemoFileHeader` in the engine's `demofile.h`). Sizes are in bytes and
 * times in seconds.
 */
final readonly class Header
{
    public const MAGIC = "spring demofile\0";

    public const VERSION = 5;

    public const SIZE = 352;

    public function __construct(
        public string $engineVersion,
        /** The 16 byte game id as 32 lower case hex digits. */
        public string $gameId,
        /** When the match started, in Unix seconds. */
        public int $startTime,
        /** Match length in game seconds. Zero when there was no game over. */
        public int $gameTime,
        public int $wallclockTime,
        public int $scriptSize,
        public int $streamSize,
        public int $numPlayers,
        public int $playerStatSize,
        public int $playerStatElemSize,
        public int $numTeams,
        public int $teamStatSize,
        public int $teamStatElemSize,
        /** Seconds between two statistics samples. */
        public int $teamStatPeriod,
        public int $winningAllyTeamsSize,
    ) {}

    /**
     * @throws ReplayException
     */
    public static function parse(string $bytes): self
    {
        if (strlen($bytes) < self::SIZE || ! str_starts_with($bytes, self::MAGIC)) {
            throw new ReplayException('This is not a Recoil replay: it does not start with the replay header.');
        }
        ['version' => $version, 'size' => $size] = unpack('lversion/lsize', $bytes, 16);
        if ($version !== self::VERSION) {
            throw new ReplayException("This replay is format version {$version}. The hub reads version ".self::VERSION.' only.');
        }
        if ($size !== self::SIZE) {
            throw new ReplayException("This replay's header is {$size} bytes. The hub reads ".self::SIZE.' byte headers only.');
        }

        $f = unpack('Ptime/lscript/lstream/lgame/lwall/lplayers/lpss/lpse/lteams/ltss/ltse/lperiod/lwin', $bytes, 296);
        foreach (['script', 'stream', 'game', 'wall', 'players', 'pss', 'pse', 'teams', 'tss', 'tse', 'win'] as $key) {
            if ($f[$key] < 0) {
                throw new ReplayException('This replay is damaged: its header has a negative size or count.');
            }
        }

        return new self(
            engineVersion: strstr(substr($bytes, 24, 256)."\0", "\0", true),
            gameId: bin2hex(substr($bytes, 280, 16)),
            startTime: $f['time'],
            gameTime: $f['game'],
            wallclockTime: $f['wall'],
            scriptSize: $f['script'],
            streamSize: $f['stream'],
            numPlayers: $f['players'],
            playerStatSize: $f['pss'],
            playerStatElemSize: $f['pse'],
            numTeams: $f['teams'],
            teamStatSize: $f['tss'],
            teamStatElemSize: $f['tse'],
            teamStatPeriod: $f['period'],
            winningAllyTeamsSize: $f['win'],
        );
    }
}

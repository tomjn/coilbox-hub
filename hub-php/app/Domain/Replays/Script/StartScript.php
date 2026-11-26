<?php

namespace App\Domain\Replays\Script;

/**
 * The start script: the text setup the match was launched with.
 */
final readonly class StartScript
{
    public function __construct(
        public string $text,
        /** The `[game]` section, for keys this class does not name. */
        public Section $game,
        public string $map,
        /** The game's name and version, such as `Beyond All Reason test-32071-e1cbffe`. */
        public string $gameType,
        public ?int $startPosType,
        /** @var array<int, Player> by player number */
        public array $players,
        /** @var array<int, Ai> by AI number */
        public array $ais,
        /** @var array<int, Team> by team number */
        public array $teams,
        /** @var array<int, AllyTeam> by ally team number */
        public array $allyTeams,
        /** @var array<string, string> */
        public array $modOptions,
        /** @var array<string, string> */
        public array $mapOptions,
    ) {}

    public static function parse(string $text): self
    {
        $root = Section::parse($text);
        $game = $root->children['game'] ?? $root;

        $teams = [];
        foreach ($game->numbered('team') as $id => $t) {
            $teams[$id] = new Team(
                id: $id,
                allyTeam: $t->int('allyteam'),
                leader: $t->int('teamleader'),
                side: self::nonEmpty($t->get('side')),
                colour: self::colour($t->get('rgbcolor')),
            );
        }

        $players = [];
        foreach ($game->numbered('player') as $id => $p) {
            $spectator = $p->get('spectator') === '1';
            $team = $spectator ? null : $p->int('team');
            $players[$id] = new Player(
                id: $id,
                name: $p->get('name') ?? '',
                spectator: $spectator,
                team: $team,
                allyTeam: $teams[$team]->allyTeam ?? null,
                side: $teams[$team]->side ?? null,
                colour: $teams[$team]->colour ?? null,
                skill: $p->get('skill'),
                skillUncertainty: $p->float('skilluncertainty'),
                countryCode: self::nonEmpty($p->get('countrycode')),
            );
        }

        $ais = [];
        foreach ($game->numbered('ai') as $id => $a) {
            $team = $a->int('team');
            $ais[$id] = new Ai(
                id: $id,
                name: $a->get('name') ?? '',
                shortName: $a->get('shortname') ?? '',
                version: self::nonEmpty($a->get('version')),
                team: $team,
                allyTeam: $teams[$team]->allyTeam ?? null,
                side: $teams[$team]->side ?? null,
                colour: $teams[$team]->colour ?? null,
                host: $a->int('host'),
            );
        }

        $allyTeams = [];
        foreach ($game->numbered('allyteam') as $id => $a) {
            $box = [
                'left' => $a->float('startrectleft'),
                'top' => $a->float('startrecttop'),
                'right' => $a->float('startrectright'),
                'bottom' => $a->float('startrectbottom'),
            ];
            $hasArea = ! in_array(null, $box, true) && $box['right'] > $box['left'] && $box['bottom'] > $box['top'];
            $allyTeams[$id] = new AllyTeam($id, $hasArea ? $box : null);
        }

        return new self(
            text: $text,
            game: $game,
            map: $game->get('mapname') ?? '',
            gameType: $game->get('gametype') ?? '',
            startPosType: $game->int('startpostype'),
            players: $players,
            ais: $ais,
            teams: $teams,
            allyTeams: $allyTeams,
            modOptions: ($game->children['modoptions'] ?? new Section)->keys,
            mapOptions: ($game->children['mapoptions'] ?? new Section)->keys,
        );
    }

    private static function nonEmpty(?string $v): ?string
    {
        return $v === null || $v === '' ? null : $v;
    }

    /** @return array{0: float, 1: float, 2: float}|null */
    private static function colour(?string $v): ?array
    {
        $parts = preg_split('/\s+/', trim($v ?? ''));
        if (count($parts) < 3 || ! is_numeric($parts[0]) || ! is_numeric($parts[1]) || ! is_numeric($parts[2])) {
            return null;
        }

        return [(float) $parts[0], (float) $parts[1], (float) $parts[2]];
    }
}

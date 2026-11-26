<?php

namespace App\Domain\Replays\Stream;

/**
 * Something that happened to a player or team during the match. What `data`
 * holds depends on the type:
 *
 * - pause: `paused` (bool)
 * - player_left: `reason` (lost_connection, left, kicked or a number)
 * - give_away: `to_team`, `from_team`
 * - resign: nothing
 * - join_team: `team`
 * - team_died: `team`
 * - team_action: `action`, `param1`, `param2`, for an action the hub does not name
 * - game_over: `winning_ally_teams` (list of int)
 * - player_name: `name`
 * - new_player: `name`, `team`, `spectator`
 */
final readonly class Event
{
    public const PAUSE = 'pause';

    public const PLAYER_LEFT = 'player_left';

    public const GIVE_AWAY = 'give_away';

    public const RESIGN = 'resign';

    public const JOIN_TEAM = 'join_team';

    public const TEAM_DIED = 'team_died';

    public const TEAM_ACTION = 'team_action';

    public const GAME_OVER = 'game_over';

    public const PLAYER_NAME = 'player_name';

    public const NEW_PLAYER = 'new_player';

    public function __construct(
        public int $frame,
        public string $type,
        public int $player,
        /** @var array<string, mixed> */
        public array $data = [],
    ) {}
}

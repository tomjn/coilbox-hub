<?php

namespace App\Domain\Games;

/**
 * The wire shape of `GET /api/v1/games`, `lib/api/gameList.ts`. Every game the
 * hub holds, with enough on each to draw a card and fetch the game.
 *
 * The envelope is `coilbox-hub-games`, the string both submission routes
 * carry. The version stays at 1: coilbox refuses a body whose version is above
 * the one it was built against, so a bump would take the listing away from
 * every shipped client.
 *
 * A picture still waiting for promotion is a root relative path, which a
 * caller resolves against the host it just called.
 */
final class GameList
{
    public const FORMAT = 'coilbox-hub-games';

    public const VERSION = 1;

    /**
     * In the order given, which is the page's: featured first, then alphabetical.
     * `releases` is left off every game when it could not be read, which a
     * client reads as not knowing rather than as a game with none.
     *
     * @param  list<array>  $games
     * @param  array<string, list<string>>|null  $releases  from `Query::fetchReleases`
     */
    public static function body(array $games, ?array $releases = null): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'games' => array_map(fn (array $game) => [
                'shortname' => $game['shortname'],
                'title' => Labels::gameTitle($game),
                'description' => $game['description'] ?? null,
                'featured' => ($game['featured_at'] ?? null) !== null,
                'downloads' => Download::readDownloads($game['downloads'] ?? null),
                'logo' => GameArt::fromRow($game, 'logo'),
                'card' => GameArt::fromRow($game, 'card'),
                'banner' => GameArt::fromRow($game, 'banner'),
                'faction_count' => $game['faction_count'] ?? 0,
                'unit_count' => $game['unit_count'] ?? 0,
                'item_count' => $game['item_count'] ?? 0,
                'base_game' => $game['base_game'] ?? null,
            ] + ($releases === null ? [] : [
                'releases' => $releases[strtolower($game['shortname'])] ?? [],
            ]), $games),
        ];
    }
}

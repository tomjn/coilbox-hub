<?php

namespace App\Domain\Games;

use App\Supabase\Client;
use App\Supabase\Local\GameStore;
use App\Supabase\SupabaseException;

/**
 * The wire shape of `GET /api/v1/games/{shortname}/factions`: every side a
 * game has, with the words its owner wrote for each. Coilbox renders the
 * description itself, so it travels as the Markdown that was written.
 *
 * Random is kept. Coilbox knows a game's sides by key and decides for itself
 * what to draw.
 */
final class FactionList
{
    public const FORMAT = 'coilbox-hub-factions';

    public const VERSION = 1;

    /**
     * Null for a game nobody holds, or one hidden from the caller.
     *
     * @return array{game: array, factions: list<array>}|null
     *
     * @throws SupabaseException when the read fails
     */
    public static function load(Client $supabase, string $shortname): ?array
    {
        // Supabase's copy of the catalogue has no faction text.
        $columns = GameStore::local() ? 'key,name,tagline,description' : 'key,name';
        $game = $supabase->from('game')
            ->select('shortname,game_faction('.$columns.')')
            ->eq('shortname', $shortname)
            ->order('id', ascending: true, foreignTable: 'game_faction')
            ->maybeSingle();

        return $game === null ? null : ['game' => $game, 'factions' => $game['game_faction'] ?? []];
    }

    /** @param array{game: array, factions: list<array>} $loaded */
    public static function body(array $loaded): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'shortname' => $loaded['game']['shortname'],
            'factions' => array_map(fn (array $faction) => [
                'key' => $faction['key'],
                'name' => $faction['name'],
                'tagline' => $faction['tagline'] ?? null,
                'description' => $faction['description'] ?? null,
            ], $loaded['factions']),
        ];
    }
}

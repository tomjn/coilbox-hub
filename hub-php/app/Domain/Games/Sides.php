<?php

namespace App\Domain\Games;

use App\Domain\Assets\AssetResolver;
use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * Each game's sides, and the unit a player starts each one with.
 *
 * A game's logo says which game it is. The start units say what it is: a BAR
 * commander, a Spring 1944 army HQ, a Zero-K nothing yet. The hub already holds
 * their buildpics for most games, so the listing card and the game's page draw
 * them rather than a tag somebody would have to write.
 *
 * A start unit belongs to a side through `game_unit.faction_key`. One the
 * catalog cannot place on a side the game lists is not drawn, because there is
 * no side to draw it against.
 *
 * ## A missing buildpic is still a unit
 *
 * A start unit the hub holds no picture for is drawn with the placeholder the
 * resolver falls through to, the way every other unit picture on the site is,
 * rather than dropped. The unit's name, its side and the fact that it is the
 * one a player starts with do not depend on whether a picture was uploaded.
 *
 * ## A game that has never said
 *
 * `start_units` is null until a client reports it, and plenty of games have
 * not. Their card draws three units picked at random out of the game's own
 * catalog, through `public.game_sample_units`. They are not the units a player
 * starts with and nothing claims they are: they are there so a reader sees what
 * the game's units look like instead of a gap.
 *
 * A side commander is `['unit_name', 'label', 'picture']` where the picture is
 * a resolved asset from `AssetResolver::resolveAsset`. A game's sides are
 * `['factions' => [['key', 'name']], 'commanders' => [factionKey => commander],
 * 'sample' => [commander]]`.
 */
final class Sides
{
    /** How many units stand in for a game that has named no start units. Three is what the narrowest card has room for beside its counts. */
    public const SAMPLE_SIZE = 3;

    /**
     * @param  array<string, mixed>  $game
     * @return list<array{key: string, name: string}>
     */
    private static function factionsOf(array $game): array
    {
        $factions = [];
        foreach ($game['game_faction'] ?? [] as $faction) {
            if (! Factions::isRandomFaction($faction)) {
                $factions[] = ['key' => $faction['key'], 'name' => $faction['name']];
            }
        }
        usort($factions, fn (array $left, array $right) => Js::localeCompare($left['name'], $right['name']));

        return $factions;
    }

    /**
     * Which side each start unit belongs to, before any picture is looked up.
     * The one definition of "this game has start units worth drawing", so the
     * loader and the assembler cannot disagree about which games need a sample.
     *
     * @param  array<string, mixed>  $game
     * @param  list<array{key: string}>  $factions
     * @param  list<array<string, mixed>>  $units
     * @return array<string, array<string, mixed>> the unit for each side, by faction key
     */
    private static function placeStartUnits(array $game, array $factions, array $units): array
    {
        $placed = [];
        foreach ($game['start_units'] ?? [] as $unitName) {
            $unit = null;
            foreach ($units as $row) {
                if ($row['game']['shortname'] === $game['shortname'] && $row['unit_name'] === $unitName) {
                    $unit = $row;
                    break;
                }
            }
            $side = $unit['faction_key'] ?? null;
            if ($side === null || $side === '' || array_key_exists($side, $placed)) {
                continue;
            }
            if (! in_array($side, array_column($factions, 'key'), true)) {
                continue;
            }
            $placed[$side] = $unit;
        }

        return $placed;
    }

    /**
     * @param  array<string, mixed>  $unit
     * @param  array<string, mixed>  $held
     * @return array{unit_name: string, label: string, picture: array<string, mixed>}
     */
    private static function drawn(string $game, array $unit, array $held): array
    {
        return [
            'unit_name' => $unit['unit_name'],
            'label' => $unit['full_name'] ?? $unit['unit_name'],
            'picture' => AssetResolver::resolveAsset(AssetResolver::unit($game, $unit['unit_name']), $held),
        ];
    }

    /**
     * The games nobody has named a placeable start unit for, which are the
     * ones a random sample stands in for.
     *
     * @param  list<array<string, mixed>>  $games
     * @param  list<array<string, mixed>>  $units
     * @return list<string>
     */
    public static function gamesNeedingSample(array $games, array $units): array
    {
        $names = [];
        foreach ($games as $game) {
            if (self::placeStartUnits($game, self::factionsOf($game), $units) === []) {
                $names[] = $game['shortname'];
            }
        }

        return $names;
    }

    /**
     * The rows and the held pictures into one answer per game, keyed by
     * shortname. Apart from the reads so a test can hand it rows.
     *
     * @param  list<array<string, mixed>>  $games
     * @param  list<array<string, mixed>>  $units
     * @param  array<string, mixed>  $held
     * @param  array<string, list<array{unit_name: string, full_name: string|null}>>  $sampled  by shortname
     * @return array<string, array<string, mixed>>
     */
    public static function assembleSides(array $games, array $units, array $held, array $sampled = []): array
    {
        $sides = [];
        foreach ($games as $game) {
            $factions = self::factionsOf($game);

            $commanders = [];
            foreach (self::placeStartUnits($game, $factions, $units) as $key => $unit) {
                $commanders[$key] = self::drawn($game['shortname'], $unit, $held);
            }

            $sample = $commanders !== []
                ? []
                : array_map(fn (array $unit) => self::drawn($game['shortname'], $unit, $held), $sampled[$game['shortname']] ?? []);

            $sides[$game['shortname']] = ['factions' => $factions, 'commanders' => $commanders, 'sample' => $sample];
        }

        return $sides;
    }

    /**
     * A few units of each named game, at random. Empty when nothing is asked
     * for or the call fails: a sample is what stands in for missing facts, so
     * failing to get one leaves the card as it was rather than the page.
     *
     * @param  list<string>  $shortnames
     * @return array<string, list<array{unit_name: string, full_name: string|null}>>
     */
    private static function sampleUnits(Client $supabase, array $shortnames): array
    {
        $sampled = [];
        if ($shortnames === []) {
            return $sampled;
        }

        try {
            $data = $supabase->rpc('game_sample_units', ['p_shortnames' => $shortnames, 'p_count' => self::SAMPLE_SIZE]);
        } catch (SupabaseException) {
            return $sampled;
        }
        if (! is_array($data)) {
            return $sampled;
        }

        foreach ($data as $row) {
            $sampled[$row['shortname']][] = ['unit_name' => $row['unit_name'], 'full_name' => $row['full_name'] ?? null];
        }

        return $sampled;
    }

    /**
     * The sides of every named game, keyed by shortname. A game that failed to
     * read is absent from the answer, and a page draws its card or header
     * without sides rather than failing, because the sides dress the page and
     * are not what it is for.
     *
     * Four reads at most, in order, because each one needs the last: the
     * games, the units their start lists name, a sample for whichever games
     * that left with nothing, and then one batch of pictures covering both
     * sets of units.
     *
     * @param  list<string>  $shortnames
     * @return array<string, array<string, mixed>>
     */
    public static function loadGameSides(Client $supabase, array $shortnames): array
    {
        if ($shortnames === []) {
            return [];
        }

        try {
            $games = $supabase
                ->from('game')
                ->select('shortname,start_units,game_faction(key,name)')
                ->in('shortname', $shortnames)
                ->get();
        } catch (SupabaseException) {
            return [];
        }

        $startUnits = [];
        foreach ($games as $game) {
            foreach ($game['start_units'] ?? [] as $unitName) {
                $startUnits[$unitName] = true;
            }
        }
        $startUnits = array_map('strval', array_keys($startUnits));

        $units = [];
        if ($startUnits !== []) {
            try {
                $units = $supabase
                    ->from('game_unit')
                    ->select('unit_name,full_name,faction_key,game!inner(shortname)')
                    ->in('game.shortname', $shortnames)
                    ->in('unit_name', $startUnits)
                    ->is('removed_at', null)
                    ->get();
            } catch (SupabaseException) {
                $units = [];
            }
        }

        $sampled = self::sampleUnits($supabase, self::gamesNeedingSample($games, $units));

        $identities = array_map(fn (array $unit) => AssetResolver::unit($unit['game']['shortname'], $unit['unit_name']), $units);
        foreach ($sampled as $shortname => $sample) {
            foreach ($sample as $unit) {
                $identities[] = AssetResolver::unit((string) $shortname, $unit['unit_name']);
            }
        }
        $held = AssetResolver::fetchHeldAssets($supabase, $identities);

        return self::assembleSides($games, $units, $held, $sampled);
    }
}

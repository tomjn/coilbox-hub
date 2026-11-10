<?php

namespace App\Domain\Games;

/**
 * The two small things every reader of the game catalog tables needs (#223):
 * what counts as a usable link, and what counts as retired. The column list is
 * held by the `20260821100000_game_catalog.sql` migration, so rows are plain
 * arrays with the column names as keys.
 */
final class Catalog
{
    /** `#rrggbb`, case insensitive, matching the pattern the edit form's colour input enforces. `\z`, not `$`, which would let a trailing newline through. */
    private const HEX_COLOR = '/^#[0-9a-f]{6}\z/i';

    /**
     * A unit a complete submission stopped listing.
     *
     * Retired rather than deleted, because an old replay still names it, which
     * is why this is a question with an answer instead of an absent row.
     *
     * @param  array<string, mixed>  $unit
     */
    public static function gameUnitIsRetired(array $unit): bool
    {
        return ($unit['removed_at'] ?? null) !== null;
    }

    /**
     * The labelled links a game row carries, as `['label', 'url']` pairs.
     *
     * jsonb arrives as whatever was stored, and the table only insists it is an
     * array. Anything inside it that is not an object carrying a non-empty
     * label and url is dropped rather than rendered, so one malformed entry
     * costs itself and not the page.
     *
     * @return list<array{label: string, url: string}>
     */
    public static function parseGameLinks(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }
        $links = [];
        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $label = $entry['label'] ?? null;
            $url = $entry['url'] ?? null;
            if (! is_string($label) || ! is_string($url)) {
                continue;
            }
            if (Js::trim($label) === '' || Js::trim($url) === '') {
                continue;
            }
            $links[] = ['label' => $label, 'url' => $url];
        }

        return $links;
    }

    /**
     * The conquest factions a game row carries, as `['name', 'color'?, 'side'?]`.
     *
     * jsonb arrives as whatever was stored, and the column only insists it is
     * an array. An entry with no usable name is dropped. A malformed colour or
     * side is dropped on its own rather than taking the whole entry with it,
     * since a name alone is still a usable faction.
     *
     * @return list<array{name: string, color?: string, side?: string}>
     */
    public static function parseConquestFactions(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }
        $factions = [];
        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $entry['name'] ?? null;
            if (! is_string($name) || Js::trim($name) === '') {
                continue;
            }
            $faction = ['name' => Js::trim($name)];
            $color = $entry['color'] ?? null;
            if (is_string($color) && preg_match(self::HEX_COLOR, $color) === 1) {
                $faction['color'] = $color;
            }
            $side = $entry['side'] ?? null;
            if (is_string($side) && Js::trim($side) !== '') {
                $faction['side'] = Js::trim($side);
            }
            $factions[] = $faction;
        }

        return $factions;
    }
}

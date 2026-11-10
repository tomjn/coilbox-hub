<?php

namespace App\Domain\Moderation;

use App\Domain\Maps\Packs;
use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * The map pack moderation pages' half of `lib/maps/packs.ts`
 * (tomjn/coilbox#3206): reading one pack, the form rules and the words the
 * forms answer with. The listing read is {@see Packs::fetch()}.
 */
final class MapPacks
{
    /** The table's own limits. */
    public const TITLE_MAX = 128;

    public const BLURB_MAX = 2000;

    public const MESSAGES = [
        'featured' => 'Pack featured. Coilbox lists it in the Map packs menu.',
        'unfeatured' => 'Pack no longer featured. Coilbox stops listing it.',
        'saved' => 'Saved.',
        'badText' => 'A pack needs a title of up to 128 characters, and a blurb of up to 2000.',
        'nothingPasted' => 'Paste at least one map name or archive filename, one per line.',
        'removed' => 'Map taken out of the pack.',
        'signedOut' => 'You are signed out. Sign in, then try again.',
        'notAllowed' => 'Only a moderator can change a map pack.',
        'notFound' => 'No pack with that id.',
        'notSaved' => 'That could not be saved. Try again in a few minutes.',
        'notSent' => 'The form did not reach the hub. Reload the page and try again.',
    ];

    /** One pack with its maps, or null when there is none or it could not be read. */
    public static function one(Client $session, Client $licence, string $id): ?array
    {
        $result = Packs::fetch($session, $licence, $id);

        return $result['error'] !== null ? null : ($result['packs'][0] ?? null);
    }

    /**
     * A pasted list as trimmed lines without blanks. Commas are not
     * separators, because map names carry them.
     *
     * @return list<string>
     */
    public static function parsePastedMapNames(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text)), fn ($line) => $line !== ''));
    }

    /**
     * A title and blurb that would pass the table's checks, or null.
     *
     * @return array{title: string, blurb: string|null}|null
     */
    public static function readText(string $title, string $blurb): ?array
    {
        $title = trim($title);
        $blurb = trim($blurb);
        if ($title === '' || mb_strlen($title) > self::TITLE_MAX || mb_strlen($blurb) > self::BLURB_MAX) {
            return null;
        }

        return ['title' => $title, 'blurb' => $blurb === '' ? null : $blurb];
    }

    /**
     * How many lines matched, and which did not, so those can be fixed and pasted again.
     *
     * @param  list<array{wanted: string, map_name: string|null}>  $results
     */
    public static function addedMessage(array $results): string
    {
        $matched = count(array_filter($results, fn ($r) => $r['map_name'] !== null));
        $missed = array_column(array_filter($results, fn ($r) => $r['map_name'] === null), 'wanted');
        $found = $matched === 1 ? '1 line matched a map.' : "{$matched} lines matched a map.";
        if ($missed === []) {
            return $found.' Maps already in the pack stay where they were.';
        }

        return $found.' No map is called: '.implode(', ', $missed).'.';
    }

    /**
     * Add pasted names through `add_maps_to_pack`, which does the matching.
     *
     * @param  list<string>  $names
     * @return array{ok: bool, message: string}
     */
    public static function addMaps(Client $admin, string $id, array $names): array
    {
        try {
            $rows = $admin->rpc('add_maps_to_pack', ['p_pack_id' => $id, 'p_names' => $names]);
        } catch (SupabaseException $e) {
            // 23503 is the foreign key: the pack was deleted under the form.
            if ($e->errorCode === '23503') {
                return ['ok' => false, 'message' => self::MESSAGES['notFound']];
            }
            report($e);

            return ['ok' => false, 'message' => self::MESSAGES['notSaved']];
        }

        return ['ok' => true, 'message' => self::addedMessage(is_array($rows) ? $rows : [])];
    }
}

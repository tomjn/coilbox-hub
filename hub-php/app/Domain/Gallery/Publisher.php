<?php

namespace App\Domain\Gallery;

use App\Domain\Container\Container;
use App\Domain\Container\Json;
use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * Everything between a pasted share code and a stored item, from the Next
 * app's `lib/gallery/publish.ts`. The rules are the desktop app's: anything
 * accepted here must be something Coilbox will import, or the gallery hands
 * out links that fail on arrival.
 */
final class Publisher
{
    /**
     * The map name a listing filters on, and the game's display name and
     * grouping key. Only payload shapes actually seen are read, since a wrong
     * map name is worse than none.
     *
     * The display name is the exact pinned build when there is one, because it
     * says which build an item is for (#92). The grouping key is only ever the
     * shortname, so a new release never mints a facet of its own (#50).
     *
     * @param  array{name?: string, shortname?: string}|null  $game
     * @return array{gameName: string|null, gameKey: string|null, mapName: string|null}
     */
    public static function describe(string $kind, mixed $payload, ?array $game): array
    {
        $gameName = $game !== null ? ($game['name'] ?? $game['shortname'] ?? null) : null;
        $gameKey = $game['shortname'] ?? null;
        $none = ['gameName' => $gameName, 'gameKey' => $gameKey, 'mapName' => null];

        if (! Container::isObject($payload)) {
            return $none;
        }
        $p = Container::props($payload);

        if ($kind === 'preset') {
            return [...$none, 'mapName' => self::str($p['mapName'] ?? null)];
        }

        if ($kind === 'setup-pack') {
            $maps = is_array($p['maps'] ?? null) ? $p['maps'] : [];

            // A pack can carry several maps and the row holds one.
            return [...$none, 'mapName' => count($maps) === 1 ? self::str($maps[0]) : null];
        }

        if ($kind === 'scenario') {
            // An export wraps the document beside its dialogue media. A bare
            // document is read the same way.
            $scenario = Container::isObject($p['scenario'] ?? null) ? Container::props($p['scenario']) : $p;
            $setup = $scenario['setup'] ?? null;

            return [...$none, 'mapName' => Container::isObject($setup) ? self::str(Container::props($setup)['mapName'] ?? null) : null];
        }

        return $none;
    }

    /**
     * Decide whether a pasted link, code or file can be published. Never
     * throws: an unusable input comes back with a reason a person can act on.
     *
     * @return array{ok: true, accepted: array{container: \stdClass, kind: string, kindVersion: int|float, gameName: string|null, gameKey: string|null, mapName: string|null}}|array{ok: false, reason: string}
     */
    public static function accept(string $input): array
    {
        $unwrapped = self::unwrapShareLink(Container::trim($input));
        if (! $unwrapped['ok']) {
            return $unwrapped;
        }
        $trimmed = $unwrapped['code'];

        $result = Container::identify($trimmed);

        if ($result['kind'] === 'unknown') {
            return ['ok' => false, 'reason' => 'That is not something coilbox made. Use Share in the app and paste the link it copies.'];
        }

        if ($result['compatibility'] === 'newer') {
            return ['ok' => false, 'reason' => 'This came from a newer coilbox than the gallery understands. It cannot be published yet.'];
        }

        if (! in_array($result['kind'], Container::GALLERY_KINDS, true)) {
            return ['ok' => false, 'reason' => 'The gallery does not carry '.$result['kind'].'s yet.'];
        }

        $decoded = Container::decodeText($trimmed);
        if ($decoded === null) {
            return ['ok' => false, 'reason' => 'That share code could not be read.'];
        }

        // A legacy bare preset has no envelope, so it gets the one it would have today.
        $container = Container::asContainer($decoded)
            ?? Container::make($result['kind'], $result['version'] ?: 1, $decoded);

        // The same ceiling the app enforces on import.
        if (strlen(Json::encode($container)) > Container::MAX_CONTAINER_BYTES) {
            return ['ok' => false, 'reason' => 'This is too large to share. Coilbox would refuse to import it.'];
        }

        // Geometry lifted from a game never goes on the hub (#507). The app
        // checks before it sends, but anyone can post a hand made code.
        if ($result['kind'] === 'module') {
            $refusal = Container::moduleGeometryRefusal($container->payload);
            if ($refusal !== null) {
                return ['ok' => false, 'reason' => $refusal];
            }
        }

        return [
            'ok' => true,
            'accepted' => [
                'container' => $container,
                'kind' => $result['kind'],
                'kindVersion' => $container->kindVersion,
                ...self::describe($result['kind'], $container->payload, $result['game'] ?? null),
            ],
        ];
    }

    /**
     * Validated fields to a stored row: run the code through {@see accept()},
     * check the title, normalise the tags and insert. The caller has already
     * established who is publishing and hands in a client whose row level
     * security runs as that user.
     *
     * @param  array{code: string, title: string, description: string, tags: list<string>}  $fields
     * @return array{ok: true, item: array<string, mixed>}|array{ok: false, reason: string, status: string}
     */
    public static function publish(Client $supabase, string $authorId, array $fields): array
    {
        $result = self::accept($fields['code']);
        if (! $result['ok']) {
            return ['ok' => false, 'reason' => $result['reason'], 'status' => 'invalid'];
        }

        $title = Container::trim($fields['title']);
        if ($title === '') {
            return ['ok' => false, 'reason' => 'Give it a title so people know what it is.', 'status' => 'invalid'];
        }

        $tags = [];
        foreach ($fields['tags'] as $tag) {
            $tag = mb_strtolower(Container::trim($tag));
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
        $tags = array_slice($tags, 0, 8);

        $accepted = $result['accepted'];
        try {
            $item = $supabase->from('item')
                ->insert([
                    'kind' => $accepted['kind'],
                    'kind_version' => $accepted['kindVersion'],
                    'title' => $title,
                    'description' => Container::trim($fields['description']),
                    'game_name' => $accepted['gameName'],
                    'game_key' => $accepted['gameKey'],
                    'map_name' => $accepted['mapName'],
                    'tags' => $tags,
                    'container' => $accepted['container'],
                    'author_id' => $authorId,
                ])
                ->select(GalleryQuery::ITEM_SUMMARY_COLUMNS)
                ->single();
        } catch (SupabaseException $e) {
            // 53400 is the rate limit trigger's errcode, passed through by PostgREST.
            $status = $e->errorCode === '53400' ? 'rate_limited' : 'storage_error';

            return ['ok' => false, 'reason' => 'Could not publish it: '.$e->getMessage(), 'status' => $status];
        }

        return ['ok' => true, 'item' => $item];
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && Container::trim($value) !== '' ? $value : null;
    }

    /**
     * Take what people actually have on their clipboard: Share copies a
     * `coilbox://import?code=` link, not a bare code.
     *
     * @return array{ok: true, code: string}|array{ok: false, reason: string}
     */
    private static function unwrapShareLink(string $trimmed): array
    {
        if ($trimmed === '') {
            return ['ok' => false, 'reason' => 'Paste a share link or code first.'];
        }
        if (! str_starts_with(strtolower($trimmed), 'coilbox://')) {
            return ['ok' => true, 'code' => $trimmed];
        }

        $query = parse_url($trimmed, PHP_URL_QUERY);
        if ($query === false) {
            return ['ok' => false, 'reason' => 'That coilbox link is malformed.'];
        }
        $params = SearchParams::parse((string) $query);

        $code = $params->get('code');
        if ($code !== null && $code !== '') {
            return ['ok' => true, 'code' => $code];
        }
        if (($params->get('url') ?? '') !== '') {
            return ['ok' => false, 'reason' => "That link points at a file hosted somewhere else. Paste the file's contents instead."];
        }

        return ['ok' => false, 'reason' => 'That is a coilbox link, but it does not carry anything to publish.'];
    }
}

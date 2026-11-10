<?php

namespace App\Domain\Games;

/**
 * The wire shape of a game branding submission, `lib/api/gameBranding.ts`.
 * One picture per request, from the game's owner or a moderator. The envelope
 * is `coilbox-hub-games`, the same as the facts route, because it is the same
 * conversation with the same client.
 */
final class GameBranding
{
    public const FORMAT = 'coilbox-hub-games';

    public const VERSION = 1;

    /** The same cap an owner's web upload is held to, so neither door is quietly narrower. */
    public const MAX_BYTES = 512 * 1024;

    /**
     * Read the two text parts. Unknown parts are ignored: a multipart body
     * carries whatever a client's HTTP stack puts in it.
     *
     * @return array{ok: true, shortname: string, kind: string}|array{ok: false, error: string}
     */
    public static function parseFields(mixed $shortname, mixed $kind): array
    {
        $shortname = Js::trim(is_string($shortname) ? $shortname : '');
        if ($shortname === '' || Js::length($shortname) > 64) {
            return ['ok' => false, 'error' => 'The "shortname" part is required and must name a game.'];
        }
        $kind = is_string($kind) ? $kind : '';
        if (! in_array($kind, GameArt::KINDS, true)) {
            return ['ok' => false, 'error' => 'The "kind" part must be one of '.implode(', ', GameArt::KINDS).'.'];
        }

        return ['ok' => true, 'shortname' => $shortname, 'kind' => $kind];
    }
}

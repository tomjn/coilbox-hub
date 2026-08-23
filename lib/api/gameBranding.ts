/**
 * The wire shape of a game branding submission (#285). A client that holds a
 * game's own art - or acts for whoever does - posts one picture per request,
 * and the hub stores it on the staging tier and names it from the game row.
 *
 * The envelope is `coilbox-hub-games`, the same format string the facts route
 * carries, because this is the second half of the same conversation with the
 * same client: facts describe a game, branding decorates it, and neither side
 * benefits from two format negotiations where one will do.
 */

export const GAME_BRANDING_FORMAT = "coilbox-hub-games";
export const GAME_BRANDING_VERSION = 1;

/** The kinds of picture a game row can carry, which are also the only values
 *  `kind` accepts. */
export type GameImageKind = "logo" | "banner";

/** How much one picture may weigh. The same number an owner's web upload is
 *  held to (`app/games/actions.ts`), because the two doors exist so a game's
 *  people can hang the same art in both places and one of them should not be
 *  quietly narrower. */
export const GAME_BRANDING_MAX_BYTES = 512 * 1024;

export interface GameBrandingResponseBody {
  format: typeof GAME_BRANDING_FORMAT;
  version: typeof GAME_BRANDING_VERSION;
  /** What happened to the picture.
   *
   * - `stored`: new art, or art whose bytes differ from what was held.
   * - `unchanged`: the exact bytes were already the ones on the row, so
   *   nothing was written and no store operation spent.
   */
  outcome: "stored" | "unchanged";
  kind: GameImageKind;
}

export type ParsedGameBrandingBody =
  | { ok: true; shortname: string; kind: GameImageKind }
  | { ok: false; error: string };

/**
 * Read the two text fields off the multipart body. Unknown-field strictness is
 * deliberately absent here: multipart forms carry whatever a client's HTTP
 * stack puts in them, and unlike the JSON declarations there is no schema to
 * drift against - the parts this route needs are named, everything else is
 * noise it ignores.
 */
export function parseGameBrandingFields(form: FormData): ParsedGameBrandingBody {
  const shortname = String(form.get("shortname") ?? "").trim();
  if (!shortname || shortname.length > 64) {
    return { ok: false, error: 'The "shortname" part is required and must name a game.' };
  }

  const kind = String(form.get("kind") ?? "");
  if (kind !== "logo" && kind !== "banner") {
    return { ok: false, error: 'The "kind" part must be "logo" or "banner".' };
  }

  return { ok: true, shortname, kind };
}

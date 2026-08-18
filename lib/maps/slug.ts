/**
 * The name a map has in a URL (#182, #187).
 *
 * Computed by the hub from the canonical map name, and never sent by a client.
 * `public.map.slug` is unique, so a client that could declare one could take the
 * URL of a map somebody else had already submitted, and the migration's own note
 * says the route is the one place that has to agree with itself about how a name
 * becomes a slug.
 *
 * `map_name` itself is never parsed anywhere, and this is not parsing it. A slug
 * is a rendering of the whole name for a URL bar, so nothing here reads a version
 * out of it or splits it into parts, and being ugly is the worst it can be.
 */

import { encodedHash } from "@/lib/assets/hash";

/**
 * How much of the name the slug keeps.
 *
 * The column holds 256 characters and {@link slugAlternative} appends nine more,
 * so the base stops here and both forms fit. A name long enough to be cut is
 * already past anything a person reads off a URL.
 */
const MAX_BASE = 240;

/** How many hex characters of the name's digest disambiguate two names that
 * slug the same. Eight is four billion, against a catalog of a few thousand. */
const SUFFIX_LENGTH = 8;

/**
 * What a map with no sluggable characters at all is called.
 *
 * A name written entirely in punctuation would otherwise produce an empty slug,
 * which the column refuses, and the map could then never be stored. Nothing in
 * the archive format stops a name like that, so it gets a name rather than a
 * refusal.
 */
const UNSLUGGABLE = "map";

/**
 * A map name as a slug: lower case, letters and digits kept, everything else a
 * single hyphen.
 *
 * The character class is Unicode letters and numbers rather than `a-z0-9`, and
 * that is the substantive choice. Mappers are worldwide and their maps are named
 * in their own scripts, so folding to ASCII would turn every Cyrillic or CJK
 * name into an empty slug and send them all through the fallback, where they
 * would be a row of hex with nothing readable in it. A percent encoded URL is
 * uglier than an ASCII one and it still says which map it is.
 *
 * Normalised to NFC first, because the same accented character has more than one
 * byte sequence and two clients can send different ones for one name. Without it
 * a map could be submitted twice and get two slugs that look identical.
 */
export function mapSlug(mapName: string): string {
  const slug = mapName
    .normalize("NFC")
    .toLowerCase()
    .replace(/[^\p{L}\p{N}]+/gu, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, MAX_BASE)
    .replace(/-+$/g, "");

  return slug.length > 0 ? slug : UNSLUGGABLE;
}

/**
 * The slug a map takes when another map already holds the first one.
 *
 * Two different canonical names can render to one slug: `Comet Catcher 1.8` and
 * `Comet_Catcher 1.8` differ as identities and not as URLs. The unique index
 * settles which map keeps the plain slug, and without a second candidate the
 * loser could never be stored at all, which would lose a real map's facts over a
 * URL collision.
 *
 * Derived from the map name rather than from the facts, so it is the same string
 * every time the map is submitted. A suffix taken from the digest would move
 * whenever the map's facts improved, and the map's URL would move with it.
 */
export async function slugAlternative(mapName: string): Promise<string> {
  const digest = await encodedHash(new TextEncoder().encode(mapName).buffer as ArrayBuffer);
  return `${mapSlug(mapName)}-${digest.slice(0, SUFFIX_LENGTH)}`;
}

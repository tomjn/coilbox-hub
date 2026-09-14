import { StorageApiError, type SupabaseClient } from "@supabase/supabase-js";
import { staticTierUrl } from "@/lib/assets/cdn";
import { encodedHash } from "@/lib/assets/hash";
import { downloadStagedAsset } from "@/lib/assets/staging";

/**
 * Where a game's logo or banner is drawn from, before and after promotion
 * (issue #345).
 *
 * An upload goes to the private staging bucket, and promotion copies it to
 * GitHub Pages overnight. Until then GitHub Pages has nothing at the path, so
 * a page that only ever named the durable tier showed a broken image to the
 * person who had just uploaded it.
 *
 * ## The URL names the hash
 *
 * Game art sits at a fixed path (`games/<shortname>/logo.webp`), and each upload
 * overwrites it. A URL keyed on the path alone would name different bytes over
 * time, so it could not be cached for long. The hub's route is
 * `/assets/games/<shortname>/<kind>/<hash>`, and it only serves bytes that hash
 * to the hash in the URL. So the response is cached as immutable, and an old
 * URL for replaced art is refused rather than answered with the new bytes.
 *
 * Pages are cached with `"use cache"`. An upload revalidates them, so they name
 * the new hash on the next request.
 *
 * ## What it will serve
 *
 * Game art has no moderation queue, because only the owner or a moderator can
 * upload it. So the test is that the game row names this hash and says the
 * bucket holds the staged copy. The row is read with the anonymous client, so
 * a hidden game's row is not found and its art is refused, as its page is.
 *
 * Art still staged in Vercel Blob is not drawn at all. Blob is suspended, so
 * its URL would be a broken image, which is what this set out to remove.
 *
 * ## After promotion
 *
 * Promotion clears the staged tier, and pages then name GitHub Pages directly.
 * A page cached before that can still name the route, so a row whose hash
 * matches and which has no staged copy is answered with a redirect to GitHub
 * Pages. That redirect is not cached. The durable path is not content
 * addressed, so a later upload and promotion would put other bytes behind it.
 */

export type GameArtKind = "logo" | "banner";

/** The three columns a game row keeps for one picture. */
export interface GameArt {
  path: string | null;
  hash: string | null;
  staged_tier: string | null;
}

/** Where the route lives, relative to the site root, with the trailing slash. */
export const GAME_ART_ROUTE_PREFIX = "/assets/games/";

/**
 * The URL a page draws a game picture from, or null when it should draw
 * nothing.
 *
 * Root relative for a staged picture, because only the hub's own pages use it.
 */
export function gameArtUrl(shortname: string, kind: GameArtKind, art: GameArt): string | null {
  if (!art.path) return null;
  // No staged copy: promoted, or imported straight to the durable tier.
  if (art.staged_tier === null) return staticTierUrl(art.path);
  if (art.staged_tier === "bucket" && art.hash) {
    return `${GAME_ART_ROUTE_PREFIX}${encodeURIComponent(shortname)}/${kind}/${art.hash}`;
  }
  return null;
}

/** One staged game picture's bytes, and the type its path says they are. */
export interface StagedGameArt {
  bytes: Blob;
  mime: string;
}

/** A game picture promotion has moved, and where the durable tier serves it. */
export interface PromotedGameArt {
  promoted: string;
}

/** The upload paths only ever end in one of these two. */
function mimeForPath(path: string): string {
  if (path.endsWith(".png")) return "image/png";
  if (path.endsWith(".webp")) return "image/webp";
  return "application/octet-stream";
}

/**
 * The bytes of a game's staged logo or banner, only when the visible game row
 * names this hash and says the bucket holds the copy, and only when the bucket
 * copy hashes to it. The durable tier URL when the row names this hash and has
 * no staged copy. Null for everything else, so the route answers every refusal
 * the same way.
 *
 * The bytes are hashed because an upload writes the object before the row. In
 * between, the row still names the old hash while the bucket already holds the
 * new bytes, and serving those under the old hash would cache them for a year.
 *
 * `anon` reads the row and must be the anonymous client (`lib/supabase/anon.ts`).
 * `admin` reads the bucket, which only the secret key can.
 *
 * Throws when the database or the bucket fails for any other reason.
 */
export async function fetchGameArt(
  anon: SupabaseClient,
  admin: SupabaseClient,
  shortname: string,
  kind: GameArtKind,
  hash: string,
): Promise<StagedGameArt | PromotedGameArt | null> {
  const { data, error } = await anon
    .from("game")
    .select(`${kind}_path,${kind}_staged_tier`)
    .eq("shortname", shortname)
    .eq(`${kind}_hash`, hash)
    .maybeSingle();

  if (error) throw error;

  const row = data as Record<string, string | null> | null;
  const path = row?.[`${kind}_path`];
  if (!row || !path) return null;

  const staged = row[`${kind}_staged_tier`];
  if (staged === null) return { promoted: staticTierUrl(path) };
  if (staged !== "bucket") return null;

  let bytes: Blob;
  try {
    bytes = await downloadStagedAsset(admin, path);
  } catch (thrown) {
    if (thrown instanceof StorageApiError && thrown.statusCode === "404") return null;
    throw thrown;
  }

  if ((await encodedHash(await bytes.arrayBuffer())) !== hash) return null;
  return { bytes, mime: mimeForPath(path) };
}

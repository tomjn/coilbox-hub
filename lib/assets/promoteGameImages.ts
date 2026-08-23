import { createHash } from "node:crypto";
import type { SupabaseClient } from "@supabase/supabase-js";
import { blobTierUrl } from "./blob";
import type { PromotionPorts } from "./promote";

/**
 * Moving a game's own art out of the staging tier and into the durable one
 * (#285).
 *
 * Ownership (#229) gave game rows `logo_path` and `banner_path`, and both the
 * web action and the branding route store uploads on the staging tier at a
 * deterministic path (`games/<shortname>/<kind>.<ext>`) - while every page
 * reads those columns through `staticTierUrl` only. Without this pass an
 * uploaded logo sits in Blob under a URL nothing renders, which is the gap this
 * closes.
 *
 * It runs beside `runPromotion` rather than inside it, because the shape is not
 * the asset pipeline's shape and pretending otherwise would bend both: a game
 * picture has no `public.asset` row, no moderation state and no random staging
 * suffix. The path on the row is the path on both tiers, so there is nothing to
 * recompute and nothing to record - which is also why there is no `blob_path`
 * column here. A later upload overwrites the staging object in place, so the
 * row's hash is the only way to tell whether the staging bytes are the ones the
 * row asked for.
 *
 * ## The order
 *
 * The same direction every step fails in as the asset run: a picture may end up
 * in both tiers, never in neither.
 *
 * 1. Read the staging bytes for each path a row names. Absent means already
 *    promoted, or written straight to the durable tier by the import script,
 *    or never uploaded at all - all ordinary, all skipped here.
 * 2. Hash what arrived against the row. Mismatch means a newer upload replaced
 *    the object between the row's write and this read; skip rather than commit
 *    bytes nobody asked for.
 * 3. Write into the checkout, overwriting whatever was there. Stale art is the
 *    case that matters: the row moved on, the durable tier has to follow.
 * 4. One commit, one push, and then the gate before anything irreversible: the
 *    durable tier must actually be serving every path. Fatal otherwise, the
 *    same reading as the asset run - nothing has been deleted yet, so stopping
 *    loses nothing.
 * 5. Delete the staging objects. Free, and safe to repeat: a later upload
 *    recreates its object through the same deterministic put.
 */

/** What the run reads off a game row. */
interface GameImagePaths {
  shortname: string;
  logo_path: string | null;
  banner_path: string | null;
  logo_hash: string | null;
  banner_hash: string | null;
}

/** One picture a row names: the shared path and the hash that vouches for the
 *  bytes. */
export interface GameImage {
  /** Which game sent it, so a skip can be named in the report. */
  shortname: string;
  kind: "logo" | "banner";
  path: string;
  hash: string;
}

/**
 * Every staging-tier picture a game row names, oldest row first.
 *
 * Wants the secret key, for the reason the asset run gives: a staging pathname
 * is a working public URL and stays on the server.
 */
export async function fetchStagedGameImages(
  supabase: SupabaseClient,
): Promise<GameImage[]> {
  const { data, error } = await supabase
    .from("game")
    .select("shortname,logo_path,banner_path,logo_hash,banner_hash");

  if (error) throw new Error(`Could not read the game rows: ${error.message}`);

  const out: GameImage[] = [];
  for (const row of (data ?? []) as unknown as GameImagePaths[]) {
    if (row.logo_path && row.logo_hash) {
      out.push({ shortname: row.shortname, kind: "logo", path: row.logo_path, hash: row.logo_hash });
    }
    if (row.banner_path && row.banner_hash) {
      out.push({
        shortname: row.shortname,
        kind: "banner",
        path: row.banner_path,
        hash: row.banner_hash,
      });
    }
  }
  return out;
}

const sha256 = (bytes: Uint8Array): string =>
  createHash("sha256").update(bytes).digest("hex");

export interface GameImagePromotionResult {
  /** Pictures found on the staging tier and pushed to the durable one. */
  promoted: number;
  /** Pictures the run looked at and left alone, each of which was said out
   *  loud. */
  skipped: number;
}

/**
 * One run. Takes the same ports the asset promotion takes, so the script wires
 * both to the same real side effects and the tests fake both the same way.
 */
export async function runGameImagePromotion(
  supabase: SupabaseClient,
  ports: PromotionPorts,
): Promise<GameImagePromotionResult> {
  const images = await fetchStagedGameImages(supabase);
  const seen = new Set<string>();
  const pushing: GameImage[] = [];
  let skipped = 0;

  for (const image of images) {
    if (seen.has(image.path)) continue;

    let bytes: Uint8Array;
    try {
      bytes = await ports.read(blobTierUrl(image.path));
    } catch {
      // Not on the staging tier: promoted by an earlier run, written straight
      // to the durable one by the import script, or simply never uploaded.
      continue;
    }
    seen.add(image.path);

    if (sha256(bytes) !== image.hash) {
      ports.say(
        `skip ${image.path}: the store returned bytes the row does not name. ` +
          `A newer upload may have landed mid-read; the next run sees it whole.`,
      );
      skipped++;
      continue;
    }

    // Overwrite unconditionally: the row's hash is the truth about what this
    // path should hold, and a checkout holding older art is the case worth
    // catching. Identical bytes stage nothing at the commit.
    await ports.write(image.path, bytes);
    pushing.push(image);
  }

  if (pushing.length === 0) {
    ports.say("No game pictures are waiting on the staging tier.");
    return { promoted: 0, skipped };
  }

  await ports.publish(pushing.map((image) => image.path));

  // The gate, and the same fatal reading as the asset run: the rows still point
  // at Blob, which still holds everything, so stopping here loses nothing.
  const live = new Set(await ports.serving(pushing.map((image) => image.path)));
  const missing = pushing.filter((image) => !live.has(image.path));
  if (missing.length > 0) {
    throw new Error(
      `Pushed ${pushing.length} game picture(s) and the durable tier is serving ${
        pushing.length - missing.length
      }. Nothing has been deleted. First missing: ${missing[0].path}`,
    );
  }

  await ports.discard(pushing.map((image) => image.path));
  ports.say(`Promoted ${pushing.length} game picture(s) and cleared their staging copies.`);

  return { promoted: pushing.length, skipped };
}

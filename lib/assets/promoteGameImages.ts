import { createHash } from "node:crypto";
import type { SupabaseClient } from "@supabase/supabase-js";
import { deleteStagedObjects } from "./orphan";
import { type PromotionPorts, StagingReadError } from "./promote";

/**
 * Moving a game's own art out of the staging tier and into the durable one
 * (#285).
 *
 * Ownership (#229) gave game rows `logo_path` and `banner_path`, and both the
 * web action and the branding route store uploads on the staging tier at a
 * deterministic path (`games/<shortname>/<kind>.<ext>`) - while every page
 * reads those columns through `staticTierUrl` only. Without this pass an
 * uploaded logo sits in the bucket under a URL nothing renders, which is the
 * gap this closes.
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
 * 1. Read the staging bytes for each path a row names, from the bucket. A 404
 *    is a row naming a copy that is not there, which is said out loud. Any
 *    other answer is the store refusing, which is said out loud and counted.
 * 2. Hash what arrived against the row. Mismatch means a newer upload replaced
 *    the object between the row's write and this read. Skip rather than commit
 *    bytes nobody asked for.
 * 3. Write into the checkout, overwriting whatever was there. Stale art is the
 *    case that matters: the row moved on, the durable tier has to follow.
 * 4. Read the rows again and take out of the checkout every game picture no
 *    row names (#360): art an owner or moderator removed, whether it was
 *    promoted days ago or written a moment ago in step 3, and art left at an
 *    old extension. A picture removed after it was read is not pushed.
 * 5. One commit, one push, and then the gate before anything irreversible: the
 *    durable tier must actually be serving every path. Fatal otherwise, the
 *    same reading as the asset run - nothing has been deleted yet, so stopping
 *    loses nothing. A removal needs no gate, because no staging copy is
 *    deleted on the strength of it.
 * 6. Clear the staged tier, then delete through a reservation
 *    (`deleteStagedObjects` in `./orphan`), which refuses if an upload has
 *    claimed the path again since. Dying between the two leaves an object
 *    nothing claims, which the bucket sweep deletes.
 *
 * ## A removal that lands mid run
 *
 * Removing art only clears the row (`removeGameImage` in `app/games/actions.ts`).
 * A removal before step 4's read is never pushed. A removal after it was read
 * as named, so the push goes ahead; the clear in step 6 then finds the row
 * changed and leaves the bucket copy to the sweep, and the next run's step 4
 * deletes the pushed file. Nothing links it in between.
 *
 * Deleting from the durable tier takes the file off GitHub Pages. It stays in
 * the assets repo's git history, which is the same limit
 * `lib/assets/withdraw.ts` describes for a takedown.
 */

/** What the run reads off a game row. */
interface GameImagePaths {
  shortname: string;
  logo_path: string | null;
  banner_path: string | null;
  logo_hash: string | null;
  banner_hash: string | null;
  logo_staged_tier: string | null;
  banner_staged_tier: string | null;
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

/** Every extension an upload, the branding route or the import script gives a
 *  game picture's path (`games/<shortname>/<kind>.<ext>`). */
const GAME_IMAGE_EXTENSIONS = ["png", "webp"] as const;

/**
 * Game picture files the durable checkout holds and no game row names, which
 * the next publish should delete from the durable tier (#360).
 *
 * Every row's two picture paths at every extension are asked about, plus
 * `written`, the paths this run has just put in the checkout, so a picture
 * whose row is gone altogether is caught too. `held` is the checkout's answer
 * (`PromotionPorts.held`).
 *
 * Wants the secret key, to read hidden games' rows, whose art is kept.
 */
export async function strayGameImages(
  supabase: SupabaseClient,
  held: (path: string) => Promise<boolean>,
  written: string[] = [],
): Promise<string[]> {
  const { data, error } = await supabase.from("game").select("shortname,logo_path,banner_path");

  if (error) throw new Error(`Could not read the game rows: ${error.message}`);

  const rows = (data ?? []) as unknown as Pick<GameImagePaths, "shortname" | "logo_path" | "banner_path">[];
  const named = new Set<string>();
  const candidates = new Set<string>(written);
  for (const row of rows) {
    if (row.logo_path) named.add(row.logo_path);
    if (row.banner_path) named.add(row.banner_path);
    for (const kind of ["logo", "banner"]) {
      for (const ext of GAME_IMAGE_EXTENSIONS) candidates.add(`games/${row.shortname}/${kind}.${ext}`);
    }
  }

  const stray: string[] = [];
  for (const path of candidates) {
    if (!named.has(path) && (await held(path))) stray.push(path);
  }
  return stray;
}

/**
 * Every picture a game row says is staged, oldest row first.
 *
 * Wants the secret key, for the reason the asset run gives: a staging path
 * stays on the server rather than reaching a caller that has no other use for
 * it.
 */
export async function fetchStagedGameImages(
  supabase: SupabaseClient,
): Promise<GameImage[]> {
  const { data, error } = await supabase
    .from("game")
    .select("shortname,logo_path,banner_path,logo_hash,banner_hash,logo_staged_tier,banner_staged_tier");

  if (error) throw new Error(`Could not read the game rows: ${error.message}`);

  const out: GameImage[] = [];
  for (const row of (data ?? []) as unknown as GameImagePaths[]) {
    if (row.logo_path && row.logo_hash && row.logo_staged_tier === "bucket") {
      out.push({
        shortname: row.shortname,
        kind: "logo",
        path: row.logo_path,
        hash: row.logo_hash,
      });
    }
    if (row.banner_path && row.banner_hash && row.banner_staged_tier === "bucket") {
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

/**
 * Say a game picture has no staged copy any more, only while the row still
 * names the path and hash that were promoted. Answers whether it did, which
 * is false when an upload has replaced the picture since it was read.
 */
async function clearStagedTier(supabase: SupabaseClient, image: GameImage): Promise<boolean> {
  const { data, error } = await supabase
    .from("game")
    .update({ [`${image.kind}_staged_tier`]: null })
    .eq("shortname", image.shortname)
    .eq(`${image.kind}_path`, image.path)
    .eq(`${image.kind}_hash`, image.hash)
    .eq(`${image.kind}_staged_tier`, "bucket")
    .select("shortname");

  if (error) {
    throw new Error(`Could not clear the staged ${image.kind} for ${image.shortname}: ${error.message}`);
  }

  return (data ?? []).length > 0;
}

const sha256 = (bytes: Uint8Array): string =>
  createHash("sha256").update(bytes).digest("hex");

export interface GameImagePromotionResult {
  /** Pictures found on the staging tier and pushed to the durable one. */
  promoted: number;
  /** Pictures the run looked at and left alone, each of which was said out
   *  loud. */
  skipped: number;
  /** Of the skipped, the ones the store would not return. */
  unreadable: number;
  /** Files deleted from the durable tier because no game row names them. */
  removed: number;
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
  let pushing: GameImage[] = [];
  let skipped = 0;
  let unreadable = 0;

  for (const image of images) {
    if (seen.has(image.path)) continue;

    let bytes: Uint8Array;
    try {
      bytes = await ports.readStaged(image.path);
    } catch (error) {
      if (error instanceof StagingReadError && error.status === 404) {
        // The row says the bucket holds a copy and it does not. Nothing here
        // can bring the bytes back, so it is said rather than cleared.
        seen.add(image.path);
        ports.say(`skip ${image.path}: the row says the bucket holds it and nothing is there.`);
        skipped++;
        continue;
      }

      // Anything else is not an absence. A suspended store answers 403 for
      // every path, and reading that as "already promoted" hides the outage.
      seen.add(image.path);
      ports.say(
        `skip ${image.path}: the store would not return it: ${
          error instanceof Error ? error.message : String(error)
        }`,
      );
      skipped++;
      unreadable++;
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

  // Read after the writes, so a removal that landed while the bytes were being
  // read is caught here rather than pushed.
  const stray = await strayGameImages(
    supabase,
    (path) => ports.held(path),
    pushing.map((image) => image.path),
  );
  for (const path of stray) {
    await ports.remove(path);
    ports.say(`remove ${path}: no game row names it.`);
  }
  const removed = new Set(stray);
  for (const image of pushing.filter((candidate) => removed.has(candidate.path))) {
    ports.say(`skip ${image.path}: its row stopped naming it after it was read.`);
    skipped++;
  }
  pushing = pushing.filter((image) => !removed.has(image.path));

  if (pushing.length === 0 && stray.length === 0) {
    ports.say("No game pictures are waiting on the staging tier.");
    return { promoted: 0, skipped, unreadable, removed: 0 };
  }

  await ports.publish(pushing.map((image) => image.path));

  if (pushing.length === 0) {
    ports.say(`Removed ${stray.length} game picture(s) no row names from the durable tier.`);
    return { promoted: 0, skipped, unreadable, removed: stray.length };
  }

  // The gate, and the same fatal reading as the asset run: the rows still point
  // at their staging store, which still holds everything, so stopping here
  // loses nothing.
  const live = new Set(await ports.serving(pushing.map((image) => image.path)));
  const missing = pushing.filter((image) => !live.has(image.path));
  if (missing.length > 0) {
    throw new Error(
      `Pushed ${pushing.length} game picture(s) and the durable tier is serving ${
        pushing.length - missing.length
      }. Nothing has been deleted. First missing: ${missing[0].path}`,
    );
  }

  // Clear the record first, and only where the row still names the bytes that
  // were pushed, then delete what nothing claims. An owner upload that lands
  // in between either claims the path first, and keeps the object, or is
  // refused while the deletion is reserved.
  const cleared: GameImage[] = [];
  for (const image of pushing) {
    if (await clearStagedTier(supabase, image)) {
      cleared.push(image);
    } else {
      ports.say(`keep ${image.path}: a newer upload or a removal changed the row after it was read.`);
    }
  }
  const gone = await deleteStagedObjects(
    supabase,
    (paths) => ports.discardStaged(paths),
    cleared.map((image) => image.path),
    true,
  );
  for (const image of cleared) {
    if (!gone.has(image.path)) {
      ports.say(`keep ${image.path}: the game row claimed it again before it could be deleted.`);
    }
  }

  ports.say(`Promoted ${pushing.length} game picture(s) and cleared their staging copies.`);
  if (stray.length > 0) {
    ports.say(`Removed ${stray.length} game picture(s) no row names from the durable tier.`);
  }

  return { promoted: pushing.length, skipped, unreadable, removed: stray.length };
}

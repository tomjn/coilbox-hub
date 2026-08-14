import type { SupabaseClient } from "@supabase/supabase-js";
import type { AssetTier } from "./asset";
import { blobTierUrl } from "./blob";
import { staticTierUrl } from "./cdn";

/**
 * The moderation queue for pictures (issue #114): what is waiting, what a
 * moderator sees of it, and the two writes that move a row out of it.
 *
 * ## Everything here reads as `service_role`, and that is the decision
 *
 * `asset_read_approved` in `20260814180000_asset_access.sql` shows `anon` and
 * `authenticated` only rows where `moderation = 'approved'`, so a queue cannot
 * be read through a session. #102 left two ways to fix that and left the choice
 * to this issue: add an `is_moderator()` select policy so a moderator's own
 * session reads pending rows, or read the queue server side with the secret key
 * and keep the table shut.
 *
 * This is the second one, and the deciding factor is what a pending row's `path`
 * is. The Blob store is public, so on a pending row the path is a working URL
 * for bytes nobody has reviewed, and Blob's random suffix is the only thing
 * keeping it out of sight (#131). A select policy would make that column
 * readable over PostgREST with the publishable key by any browser holding a
 * moderator session, which puts the one secret the queue rests on into a
 * browser, where an extension, an XSS or a shared machine reaches it. Nothing
 * would then be able to take it back: the path cannot be rotated without
 * rewriting the object, and the object is what is being protected.
 *
 * Reading server side keeps the path on the server. What reaches the browser is
 * a row id and a URL on the hub's own origin, and `app/moderation/assets/[id]`
 * checks `is_moderator()` on every request before it serves a byte, so access is
 * re-decided per request rather than handed out once.
 *
 * The other two reasons both point the same way. A policy is a migration against
 * a live database that widens the one rule #102 says re-exposes every pending
 * path in a single step, and the repo has already been burned twice by grants
 * drifting from migrations (#27, #59). And an unwritten policy is the cheapest
 * kind to assert: `supabase/tests/asset_access.test.sql` proves a moderator's
 * session still reads only approved rows, and that `asset` carries exactly one
 * policy.
 */

/**
 * How many pictures one contact sheet shows.
 *
 * Chosen to fit the corpus rather than the screen. The reviewer is pattern
 * matching against "this is a game asset" over a few hundred thumbnails, so a
 * page that holds a normal day's uploads is one pass and one click. Above that
 * the page gets slow to render and the reviewer stops looking properly, which is
 * the failure the contact sheet exists to avoid.
 *
 * It also bounds what one submission can approve, so a form posted twice or a
 * stale tab cannot act on more than a page of rows.
 */
export const QUEUE_PAGE_SIZE = 240;

/** The columns a tile needs. Deliberately not `path`: see the note at the top of
 * this file. Nothing that builds a tile has any use for it, so the safest place
 * for the rule is the select list. */
const TILE_COLUMNS =
  "id, game, unit_name, map_name, variant, width, height, bytes, origin, source_archive, seen_at";

interface QueueRow {
  id: string;
  game: string | null;
  unit_name: string | null;
  map_name: string | null;
  variant: string;
  width: number;
  height: number;
  bytes: number;
  origin: string;
  source_archive: string;
  seen_at: string;
}

/**
 * One picture as the grid shows it.
 *
 * A view model rather than a row, because a server component's props are
 * serialised into the page and shipped to the browser. Anything on this type is
 * something a moderator's browser holds, so the type is the list of what the
 * grid is allowed to disclose, and `path` is not on it.
 */
export interface QueuedPicture {
  id: string;
  /** What this is a picture of, for the caption and the alt text. */
  name: string;
  /** Which picture of it, and for a unit which game. */
  detail: string;
  width: number;
  height: number;
  bytes: number;
  /** Where the bytes came from, which is the one thing on a tile that says how
   * much attention it deserves: `uploaded` is the class the queue exists for. */
  origin: string;
  sourceArchive: string;
}

/**
 * The two identity shapes as a caption. A map is not scoped to a game, so it
 * does not name one. A unit is, and unit names repeat across games, so it does.
 */
export function pictureCaption(row: {
  game: string | null;
  unit_name: string | null;
  map_name: string | null;
  variant: string;
}): { name: string; detail: string } {
  return row.map_name === null
    ? { name: row.unit_name ?? "", detail: `${row.game ?? ""} ${row.variant}` }
    : { name: row.map_name, detail: row.variant };
}

export interface PictureQueue {
  waiting: QueuedPicture[];
  /** Everything pending, which is more than `waiting` once a day's uploads pass
   * {@link QUEUE_PAGE_SIZE}. The grid says so rather than implying the page is
   * the whole queue. */
  total: number;
}

/**
 * The oldest pending pictures, oldest first, because the queue is worked from
 * the front and an upload that keeps being pushed off the end never gets looked
 * at.
 *
 * Wants a client that bypasses row level security. Read the note at the top of
 * this file, and `lib/supabase/admin.ts`.
 */
export async function fetchPictureQueue(
  supabase: SupabaseClient,
  limit = QUEUE_PAGE_SIZE,
): Promise<PictureQueue> {
  const { data, count } = await supabase
    .from("asset")
    .select(TILE_COLUMNS, { count: "exact" })
    .eq("moderation", "pending")
    .order("seen_at", { ascending: true })
    .limit(limit);

  const rows = (data ?? []) as unknown as QueueRow[];

  return {
    waiting: rows.map((row) => ({
      id: row.id,
      ...pictureCaption(row),
      width: row.width,
      height: row.height,
      bytes: row.bytes,
      origin: row.origin,
      sourceArchive: row.source_archive,
    })),
    total: count ?? rows.length,
  };
}

/**
 * Where a row's bytes actually are.
 *
 * Two rungs of the ladder #108 owns, called directly because the resolver does
 * not exist yet and the queue needs an answer today. #108 replaces the body and
 * keeps the shape: given a row, an absolute URL. The rungs it adds, the atlas
 * and the substitutes, are all things to show in place of a picture the hub
 * holds, and a moderator has to see the picture itself, so this caller will
 * always want the plain object.
 */
export function assetTierUrl(tier: AssetTier, path: string): string {
  return tier === "static" ? staticTierUrl(path) : blobTierUrl(path);
}

export interface AssetObject {
  url: string;
  mime: string;
}

/**
 * The object one row points at, or null when there is no such row.
 *
 * Every moderation state, not just pending. A moderator looking at a picture
 * they have just approved or rejected should not get a broken image, and an
 * approved row's bytes are public anyway.
 */
export async function fetchAssetObject(
  supabase: SupabaseClient,
  id: string,
): Promise<AssetObject | null> {
  const { data } = await supabase
    .from("asset")
    .select("path, tier, mime")
    .eq("id", id)
    .maybeSingle();

  if (!data) return null;

  const row = data as unknown as { path: string; tier: AssetTier; mime: string };
  return { url: assetTierUrl(row.tier, row.path), mime: row.mime };
}

/**
 * What may be one of the ids a submitted form acts on.
 *
 * A form field is whatever the browser sent, and these go straight into a filter
 * on a write.
 */
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * The ids a submitted form is allowed to act on.
 *
 * Held to the shape of a uuid, so anything else is dropped rather than passed
 * on, and capped at a page, so a hand written post cannot approve the whole
 * table in one call. Duplicates collapse, because a repeated id is a form bug
 * rather than a second row.
 */
export function pictureIds(values: string[]): string[] {
  return [...new Set(values.filter((value) => UUID.test(value)))].slice(0, QUEUE_PAGE_SIZE);
}

/**
 * Approve pictures, and return how many rows actually moved.
 *
 * `moderation` and `approval_source` are set together because
 * `asset_approval_state_check` will not have an approved row that does not say
 * what approved it. `moderator` is the value for a person doing it in the grid,
 * as against `seed` and `bypass`, which are the two ways a row skips this queue
 * entirely.
 *
 * Narrowed to rows that are still pending. A moderator holding a tab open from
 * an hour ago should not re-approve something another moderator has since
 * rejected, and without this filter the stale tab wins.
 */
export async function approvePictures(
  supabase: SupabaseClient,
  ids: string[],
): Promise<number> {
  if (ids.length === 0) return 0;

  const { data } = await supabase
    .from("asset")
    .update({ moderation: "approved", approval_source: "moderator" })
    .in("id", ids)
    .eq("moderation", "pending")
    .select("id");

  return data?.length ?? 0;
}

/**
 * Reject one picture, so the anomaly leaves the queue rather than coming back on
 * the next page.
 *
 * The whole of rejection that the grid needs, and deliberately not the whole of
 * #115. It records no reason, keeps no audit row and tells the uploader nothing.
 * `approval_source` is untouched on purpose: the table's rule is that on a
 * rejected row it reads "how this was approved before it was rejected", which is
 * the record #115 needs when a rejection overturns an approval.
 *
 * Nothing deletes the object. Nobody holds delete on `public.asset` and the
 * bytes stay in the store for #113 to sweep, so a rejection is reversible by a
 * person who can reach the database and irreversible through the hub.
 */
export async function rejectPicture(
  supabase: SupabaseClient,
  id: string,
): Promise<boolean> {
  const { data } = await supabase
    .from("asset")
    .update({ moderation: "rejected" })
    .eq("id", id)
    .eq("moderation", "pending")
    .select("id");

  return (data?.length ?? 0) > 0;
}

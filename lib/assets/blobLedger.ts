import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * Counting advanced operations the way Vercel counts them.
 *
 * Every `put()` reserves a row in `public.blob_put` first, through a function
 * that checks the budget and writes the row under one lock, and the budget is
 * measured over the last {@link BLOB_WINDOW_DAYS} days. The migration
 * (`20260914120000_blob_put_ledger.sql`) says why each of those choices is the
 * one that does not end in a suspension.
 *
 * Only `./blob` reserves. A put that could skip the reservation is the kind of
 * operation the hub could not see in August, so the reservation lives inside
 * the function that makes the put rather than at its call sites.
 */

/**
 * The window Vercel measures Hobby usage over. Rolling, with no billing cycle:
 * an operation stops counting 30 days after it was made, not on the 1st.
 */
export const BLOB_WINDOW_DAYS = 30;

/** The advanced operations one Hobby store gets in any 30 days. Read off the
 *  store dashboard on 2026-08-14 (#99). `put()`, `copy()` and `list()` count. */
export const BLOB_ADVANCED_OPERATIONS_ALLOWANCE = 2000;

/**
 * How many `put()` calls the hub will make in any 30 days.
 *
 * The margin below the allowance is for the operations nothing here makes:
 * listing the store in the Vercel dashboard is one, and there were 35 of those
 * in the 30 days to 2026-09-14.
 */
export const BLOB_PUT_BUDGET = BLOB_ADVANCED_OPERATIONS_ALLOWANCE - 100;

export type BlobPutKind = "asset" | "game_image";

export type BlobPutReservation =
  | { ok: true; id: number }
  | { ok: false; reason: "full" | "unavailable" };

/**
 * Reserve one `put()`. `supabase` must be the secret key client.
 *
 * `unavailable` when the database could not answer. That is a refusal too:
 * writing without a reservation is exactly the uncounted put this exists to
 * stop.
 */
export async function reserveBlobPut(
  supabase: SupabaseClient,
  kind: BlobPutKind,
): Promise<BlobPutReservation> {
  const { data, error } = await supabase.rpc("reserve_blob_put", {
    put_kind: kind,
    budget: BLOB_PUT_BUDGET,
  });

  if (error) return { ok: false, reason: "unavailable" };
  if (data === null) return { ok: false, reason: "full" };
  return { ok: true, id: Number(data) };
}

/**
 * Give a reservation back, for a put the store refused outright.
 *
 * Best effort. A reservation that stays is one operation over-counted for 30
 * days, which is the safe direction to be wrong in.
 */
export async function releaseBlobPut(supabase: SupabaseClient, id: number): Promise<void> {
  await supabase.rpc("release_blob_put", { put_id: id }).then(
    () => undefined,
    () => undefined,
  );
}

/** The start of the window, as PostgREST wants it. */
export function blobWindowStart(now: Date): string {
  return new Date(now.getTime() - BLOB_WINDOW_DAYS * 24 * 60 * 60 * 1000).toISOString();
}

/** One UTC day of the window, split by what the puts were for. */
export interface BlobPutDay {
  /** `YYYY-MM-DD`, UTC. */
  day: string;
  asset: number;
  gameImage: number;
}

const DAY_MS = 24 * 60 * 60 * 1000;

/**
 * Every day the window touches, oldest first, with the days nothing was put on
 * filled in as zero.
 *
 * That is {@link BLOB_WINDOW_DAYS} + 1 days, because the window starts part way
 * through its first one. A chart that dropped the empty days would draw one busy
 * week as a busy month.
 *
 * Null when the query failed. Null is not a quiet month.
 */
export async function fetchBlobPutDays(
  supabase: SupabaseClient,
  now: Date = new Date(),
): Promise<BlobPutDay[] | null> {
  const { data, error } = await supabase.rpc("blob_put_days");
  if (error) return null;

  const days = new Map<string, BlobPutDay>();
  const first = new Date(now.getTime() - BLOB_WINDOW_DAYS * DAY_MS);
  for (let at = 0; at <= BLOB_WINDOW_DAYS; at++) {
    const day = new Date(first.getTime() + at * DAY_MS).toISOString().slice(0, 10);
    days.set(day, { day, asset: 0, gameImage: 0 });
  }

  for (const row of (data ?? []) as { day: string; kind: BlobPutKind; puts: number }[]) {
    const held = days.get(row.day);
    if (!held) continue;
    if (row.kind === "asset") held.asset += row.puts;
    else held.gameImage += row.puts;
  }

  return [...days.values()];
}

/**
 * The UTC day uploads are accepted again, or null when they are accepted now.
 *
 * Walks the window from its oldest day, taking each day's puts off the total as
 * that day ages out, until the total is under the budget. A put ages out 30 days
 * to the minute after it was made, so room made by puts late on a day arrives
 * late on the day this names. That is the reading a moderator needs: which day
 * to expect, not which minute.
 */
export function uploadsResume(days: BlobPutDay[], budget: number = BLOB_PUT_BUDGET): string | null {
  let used = days.reduce((sum, day) => sum + day.asset + day.gameImage, 0);
  if (used < budget) return null;

  for (const day of days) {
    used -= day.asset + day.gameImage;
    if (used < budget) {
      const date = new Date(`${day.day}T00:00:00.000Z`);
      return new Date(date.getTime() + BLOB_WINDOW_DAYS * DAY_MS).toISOString().slice(0, 10);
    }
  }

  return null;
}

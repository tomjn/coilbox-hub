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

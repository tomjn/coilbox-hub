import type { SupabaseClient } from "@supabase/supabase-js";
import type { DownloadKind } from "@/lib/games/download";

/**
 * The queue of download sources coilbox has offered and nobody has decided yet
 * (#408).
 *
 * ## Why the read is the secret key and not the reader's own session
 *
 * `public.game_download_offer` grants `anon` and `authenticated` nothing and
 * carries no policy, so a browser session reads no offer at all, a moderator's
 * included. That is the same position `public.asset` leaves a pending picture
 * in, and `lib/assets/queue.ts` reads that queue the same way and for the same
 * reason: a held row is one somebody untrusted wrote, and the surest way for it
 * never to reach a reader is for there to be no grant that could carry it.
 *
 * So the page asks who is looking before it asks what is waiting, and only then
 * spends the secret key. `editableGame` answers the first question through row
 * level security, which is what makes it safe to answer the second outside it.
 *
 * ## Deciding is one call and it checks for itself
 *
 * `decideDownloadOffer` runs the reader's own session rather than the secret
 * key, because `public.decide_game_download_offer` reads `auth.uid()` to work
 * out whether this is the game's owner or a moderator. A page's own check
 * decides whether a page renders and decides nothing about what a request may
 * do, so the right is asked again inside the function every time.
 */

export interface DownloadOffer {
  id: number;
  shortname: string;
  kind: DownloadKind;
  value: string;
  asset: string | null;
  filename: string | null;
  created_at: string;
}

interface OfferRow {
  id: number;
  kind: string;
  value: string;
  asset: string | null;
  filename: string | null;
  created_at: string;
  game: { shortname: string } | null;
}

/**
 * Every offer still waiting, oldest first, for one game or for all of them.
 *
 * Oldest first because that is the order somebody should work through them in,
 * and the same order the ownership queue reads.
 */
export async function fetchDownloadOffers(
  admin: SupabaseClient,
  gameId?: string,
): Promise<DownloadOffer[]> {
  let query = admin
    .from("game_download_offer")
    .select("id,kind,value,asset,filename,created_at,game(shortname)")
    .eq("state", "held")
    .order("created_at", { ascending: true });
  if (gameId) query = query.eq("game_id", gameId);

  const { data, error } = await query;
  if (error) {
    console.error("fetchDownloadOffers: the queue could not be read", error);
    return [];
  }

  return ((data ?? []) as unknown as OfferRow[]).map((row) => ({
    id: row.id,
    shortname: row.game?.shortname ?? "",
    kind: row.kind as DownloadKind,
    value: row.value,
    asset: row.asset,
    filename: row.filename,
    created_at: row.created_at,
  }));
}

/**
 * What came of asking to accept an offer or turn one down.
 *
 * `gone` and `refused` are kept apart because they are different sentences to
 * whoever pressed the button. Nothing to decide is two people working one
 * queue, and the work really is done. Not theirs to decide is an owner who lost
 * the game, or a hand written post from somebody who never had it.
 */
export type OfferDecision = "decided" | "gone" | "refused" | "failed";

/** The error code Postgres raises for `insufficient_privilege`, which is what
 *  `decide_game_download_offer` throws at anybody who is neither the game's
 *  owner nor a moderator. */
const INSUFFICIENT_PRIVILEGE = "42501";

/**
 * Accept an offer, or turn it down.
 *
 * The function does the deciding, including working out whether this caller may.
 * Everything here is reading which of the four things happened out of one call.
 */
export async function decideDownloadOffer(
  supabase: SupabaseClient,
  offerId: number,
  accept: boolean,
): Promise<OfferDecision> {
  const { data, error } = await supabase.rpc("decide_game_download_offer", {
    p_offer_id: offerId,
    p_accept: accept,
  });

  if (error) {
    if (error.code === INSUFFICIENT_PRIVILEGE) return "refused";
    console.error(`decideDownloadOffer: offer ${offerId} was not decided`, error);
    return "failed";
  }
  return data === true ? "decided" : "gone";
}

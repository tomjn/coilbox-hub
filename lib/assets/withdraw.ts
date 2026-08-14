import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * The takedown queue: pictures that reached the durable tier and were rejected
 * on safety grounds afterwards (issue #153).
 *
 * ## Why the hub says it and does not do it
 *
 * Promotion moves an approved picture into tomjn/coilbox-assets seven days
 * after it is approved, and a safety problem is the kind that gets reported
 * long after somebody approved the picture. By then the bytes are in a public
 * git history and rejecting the row reaches the row and nothing else.
 *
 * The record is automatic, because it has to be: a trigger writes it, so no
 * writer can decline to. Everything after that is a person's, and the line is
 * drawn there deliberately.
 *
 * Taking the file off the published site is a commit against a repository this
 * hub does not own. Taking it out of the history is `git filter-repo` and a
 * force push that breaks every clone anybody has. Neither is a thing to give a
 * daily job and a stored credential, and doing only the first would be worse
 * than doing neither: the site would stop serving it while the blob stayed
 * fetchable at its old commit, which reads as finished and is not. So they
 * happen together, by hand, and the hub's job is to make sure nobody has to
 * remember that they are owed.
 *
 * Which is why this is read on every promotion run and said out loud whether
 * anything was promoted or not. An outstanding takedown that only shows up on a
 * page somebody has to visit is an outstanding takedown nobody sees.
 *
 * ## Why promotion is not gated on this
 *
 * It would be the wrong lever. Promotion takes approved rows and a safety
 * rejected row is not one, so nothing outstanding here can be promoted anyway,
 * and stopping unrelated pictures from moving because an old one has not been
 * taken down yet punishes the wrong batch. Saying it every run is the pressure.
 */

/** One picture that is in the durable tier and should not be. */
export interface OutstandingWithdrawal {
  asset_id: string;
  /** The durable path, as it was when the rejection landed. */
  path: string;
  /** When the rejection landed, which is how overdue this is. */
  at: string;
}

/**
 * Everything still owed, oldest first.
 *
 * Wants the secret key. `authenticated` and `anon` hold nothing on the table,
 * because the list names a file the hub is trying to take down.
 */
export async function fetchOutstandingWithdrawals(
  supabase: SupabaseClient,
): Promise<OutstandingWithdrawal[]> {
  const { data, error } = await supabase
    .from("asset_withdrawal")
    .select("asset_id, path, at")
    .is("withdrawn_at", null)
    .order("at", { ascending: true });

  if (error) throw new Error(`Could not read the takedown queue: ${error.message}`);

  return (data ?? []) as unknown as OutstandingWithdrawal[];
}

/**
 * Say that the files are gone, once somebody has removed them.
 *
 * Answers with how many rows it settled, which is not always how many were
 * asked about: an id that was never queued, or was settled already, settles
 * nothing. The caller reports the number rather than assuming it.
 */
export async function recordWithdrawn(
  supabase: SupabaseClient,
  ids: string[],
): Promise<number> {
  if (ids.length === 0) return 0;

  const { data, error } = await supabase.rpc("record_asset_withdrawn", { ids });

  if (error) throw new Error(`Could not settle the takedown: ${error.message}`);

  return typeof data === "number" ? data : 0;
}

/**
 * What a run says about the queue.
 *
 * One line per picture and a line saying what to do about them, because the
 * person reading a job's output at eight in the morning is not the person who
 * rejected the picture and may not know that a commit is not enough.
 *
 * Empty when nothing is owed, so an ordinary run stays quiet and a run with
 * something in it does not.
 */
export function withdrawalReport(outstanding: OutstandingWithdrawal[]): string[] {
  if (outstanding.length === 0) return [];

  return [
    `${outstanding.length} picture(s) rejected on safety grounds are still in the durable tier:`,
    ...outstanding.map((row) => `  ${row.path}  (${row.asset_id}, rejected ${row.at})`),
    "Remove each one from the published site and from the repository history, then record it",
    "with `bun run promote:assets --withdrawn <asset-id>`. A commit alone is not enough: the",
    "blob stays fetchable at its old commit until the history is rewritten.",
  ];
}

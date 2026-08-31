/**
 * Every row a query answers with, however many requests that takes.
 *
 * PostgREST caps a response at `max_rows`, which `supabase/config.toml` sets to
 * 1000. A read that asks for a whole game's units and takes what comes back is
 * therefore not short by a little on a big game, it is wrong: `GAME_FACTS_MAX_UNITS`
 * lets a game carry 2000 units, and the half past the cap simply is not there.
 * Nothing says so. The read succeeds, the answer is partial, and every walk over
 * it draws a game that does not exist.
 *
 * The loop advances by what arrived rather than by what it asked for, so it is
 * right whatever the cap is set to, including a deployment that lowered it.
 * It stops on the first empty page rather than on a short one, because a short
 * page is exactly what the cap produces.
 *
 * Two things a caller has to get right:
 *
 * - Order the query. Without a stable order a page boundary repeats some rows
 *   and skips others, and nothing about the result says which.
 * - Do not call `range` yourself. This owns the window, and a caller's own
 *   range would silently bound the whole read to one page again.
 *
 * Null on a failed read, the way the callers' own single reads already answer,
 * so error handling does not change shape when a read learns to page.
 */

/** What one request may carry, matching `max_rows` in `supabase/config.toml`.
 *  A guess here costs nothing: the loop reads the real cap off the answers. */
export const READ_ALL_PAGE = 1000;

/** A safety rail on a server that never returns an empty page. Two thousand
 *  pages is two million rows, far past anything this hub stores, so reaching it
 *  means the loop is not terminating rather than that the data is large. */
const MAX_PAGES = 2_000;

export async function readAll<T>(
  page: (
    from: number,
    to: number,
  ) => PromiseLike<{ data: unknown; error: unknown }>,
): Promise<T[] | null> {
  const rows: T[] = [];
  let from = 0;

  for (let requests = 0; requests < MAX_PAGES; requests += 1) {
    const { data, error } = await page(from, from + READ_ALL_PAGE - 1);
    if (error || !data) return null;

    const held = data as T[];
    rows.push(...held);
    if (held.length === 0) return rows;
    from += held.length;
  }

  return rows;
}

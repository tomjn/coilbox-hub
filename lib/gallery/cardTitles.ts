/**
 * The title a card shows, disambiguated from a twin sharing its page (issue
 * #311).
 *
 * Two items with the same title, same kind and same author read as one item
 * listed twice: nothing else on a card says which is which. Coilbox's answer
 * is the one this borrows: a title that repeats among the rows a page is
 * about to render gets a short tail of its item id appended, so "SF Double
 * Cold Fusion" and its twin read as "SF Double Cold Fusion #b460ed" and "SF
 * Double Cold Fusion #babeed". A title with no twin on the page is left
 * exactly as its author wrote it.
 *
 * The comparison is by what a reader sees, not by the raw column: trimmed and
 * case folded, so "Foo" and "foo " count as the same title even though a
 * database `=` would not. Whitespace and case are not what makes two items
 * different from each other.
 *
 * This is deliberately per page rather than global, computed from the rows a
 * caller already has rather than from a database query of its own. Two items
 * sharing a title only matter when a reader can see them side by side. A
 * suffix earned by some item three pages away would be noise on every page it
 * is not on.
 *
 * The tail is the id's last six characters. Ids here are UUIDs
 * (`supabase/migrations/20260809151352_gallery_items.sql`), so six characters
 * is six hex digits: long enough that two ids on the same page collide by
 * chance only in the sort of coincidence not worth coding for, short enough
 * to read as a tag rather than as data, and the same length coilbox settled
 * on for its own ids.
 */

const TAIL_LENGTH = 6;

/** A title as a reader sees it, for comparing whether two are "the same"
 *  title. Trimmed and case folded, since neither stray whitespace nor case
 *  makes two items different from each other. */
function normalise(title: string): string {
  return title.trim().toLowerCase();
}

/** What a card shows in place of its raw title. */
export interface CardTitle {
  /** The title exactly as its author wrote it. */
  title: string;
  /** The id tail to show alongside it, or null when this title has no twin
   *  on the page and needs none. */
  tail: string | null;
}

/**
 * The title to show for every item among `items`, keyed on item id.
 *
 * Only items whose title (trimmed, case folded) repeats among `items` get a
 * tail. `items` should be exactly the rows a page is about to render, since
 * that is what "repeats" is measured against.
 */
export function cardTitles(
  items: { id: string; title: string }[],
): ReadonlyMap<string, CardTitle> {
  const counts = new Map<string, number>();
  for (const item of items) {
    const key = normalise(item.title);
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }

  const titles = new Map<string, CardTitle>();
  for (const item of items) {
    const duplicated = (counts.get(normalise(item.title)) ?? 0) > 1;
    titles.set(item.id, {
      title: item.title,
      tail: duplicated ? item.id.slice(-TAIL_LENGTH) : null,
    });
  }
  return titles;
}

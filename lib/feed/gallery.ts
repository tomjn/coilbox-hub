import { GALLERY_KINDS, type GalleryKind } from "@/lib/container";
import { itemLabel, kindLabelPlural } from "@/lib/gallery/label";
import { atomDocument } from "./atom";

/** How many entries the feed holds. A reader polling the feed sees every item
 *  published since its last poll as long as fewer than this arrive between
 *  polls. */
export const FEED_ENTRIES = 50;

export const FEED_COLUMNS = "id,kind,mode,title,description,author_name,created_at,updated_at";

/** A row as the feed needs it. */
export interface FeedRow {
  id: string;
  kind: GalleryKind;
  mode: string | null;
  title: string;
  description: string;
  author_name: string;
  created_at: string;
  updated_at: string;
}

export type FeedKind = { ok: true; kind: GalleryKind | null } | { ok: false; error: string };

/** The `kind` a request asked for. Absent or empty is every kind. Anything that
 *  is not a gallery kind is refused, because an empty feed would read as "nothing
 *  has been published" to a reader that mistyped the address. */
export function parseFeedKind(value: string | null): FeedKind {
  if (!value) return { ok: true, kind: null };
  const kind = GALLERY_KINDS.find((known) => known === value);
  if (!kind) return { ok: false, error: `Unknown kind. Use one of: ${GALLERY_KINDS.join(", ")}.` };
  return { ok: true, kind };
}

/** The time of a feed with nothing in it. Fixed, because the feed is cached and
 *  the clock is not an answer to "when did this last change". */
const EMPTY_FEED_TIME = "1970-01-01T00:00:00.000Z";

/**
 * The feed for the newest items, newest first.
 *
 * Entry ids are `urn:uuid:` and the item's id, so they stay the same if the
 * domain changes. The feed's own id is its address, which is what a feed reader
 * keys the subscription on anyway.
 */
export function galleryFeed(rows: FeedRow[], kind: GalleryKind | null, origin: string): string {
  const held = rows.slice(0, FEED_ENTRIES);
  const query = kind ? `?kind=${kind}` : "";
  const newest = held.reduce((latest, row) => Math.max(latest, Date.parse(row.updated_at)), Number.NEGATIVE_INFINITY);

  return atomDocument({
    id: `${origin}/feed.xml${query}`,
    title: kind ? `Coilbox Hub: ${kindLabelPlural(kind)}` : "Coilbox Hub",
    subtitle: kind
      ? `New ${kindLabelPlural(kind).toLowerCase()} published on Coilbox Hub.`
      : "New things people have published on Coilbox Hub.",
    selfUrl: `${origin}/feed.xml${query}`,
    alternateUrl: `${origin}/gallery${query}`,
    updated: Number.isFinite(newest) ? new Date(newest).toISOString() : EMPTY_FEED_TIME,
    author: "Coilbox Hub",
    entries: held.map((row) => ({
      id: `urn:uuid:${row.id}`,
      title: row.title,
      link: `${origin}/item/${row.id}`,
      published: row.created_at,
      updated: row.updated_at,
      author: row.author_name,
      summary: row.description,
      category: { term: row.kind, label: itemLabel(row.kind, row.mode) },
    })),
  });
}

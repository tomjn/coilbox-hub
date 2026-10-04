import { cacheLife, cacheTag } from "next/cache";
import { TAGS } from "@/lib/cache/tags";
import type { GalleryKind } from "@/lib/container";
import { siteUrl } from "@/lib/site";
import { createAnonClient } from "@/lib/supabase/anon";
import { FEED_COLUMNS, FEED_ENTRIES, type FeedRow, galleryFeed } from "./gallery";

/**
 * The feed document for one kind, or for all of them, held between requests.
 *
 * The anonymous client means row level security decides what is public, so a
 * withdrawn item is not in the answer. Publishing, editing or withdrawing an
 * item clears the `items` tag. The hour is the floor under a change nobody made
 * through the app, the same as every other listing.
 *
 * `kind` is the argument and so the cache key. The route validates it first, so
 * the cache never holds an entry for a kind that does not exist.
 *
 * A failed read throws. Returning an empty feed instead would be cached, and a
 * reader would take it for a hub with nothing on it.
 */
export async function feedXml(kind: GalleryKind | null): Promise<string> {
  "use cache";
  cacheLife("hours");
  cacheTag(TAGS.items);

  let query = createAnonClient().from("item").select(FEED_COLUMNS);
  if (kind) query = query.eq("kind", kind);
  const { data, error } = await query.order("created_at", { ascending: false }).limit(FEED_ENTRIES);
  if (error) throw new Error(`Could not read the newest items for the feed: ${error.message}`);

  return galleryFeed((data ?? []) as unknown as FeedRow[], kind, siteUrl());
}

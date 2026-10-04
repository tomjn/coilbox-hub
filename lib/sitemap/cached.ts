import { cacheLife, cacheTag } from "next/cache";
import { TAGS } from "@/lib/cache/tags";
import { fetchPublishedMapNames } from "@/lib/maps/lookup";
import { createAdminClient } from "@/lib/supabase/admin";
import { createAnonClient } from "@/lib/supabase/anon";
import { getSupabaseConfig } from "@/lib/supabase/config";
import { readAll } from "@/lib/supabase/readAll";
import type { SitemapSource } from "./entries";

/**
 * What the sitemap lists, one loader per tag so a write clears only what it
 * touched.
 *
 * Each reads every row, through `readAll` because PostgREST caps one response
 * below the size of the catalog. A read that fails throws, because a cached
 * short list would drop pages from the sitemap for an hour.
 *
 * `next build` prerenders the sitemap and can run with no Supabase settings, as
 * CI does. Each loader returns an empty list then, the same as the listings
 * (`lib/gallery/cached.ts`). A deployment that really lacks them fails every
 * request in `proxy.ts`, so this hides nothing.
 */

const LISTING_LIFE = "hours";

const notConfigured = () => !getSupabaseConfig().ok;

function all<T>(rows: T[] | null, what: string): T[] {
  if (!rows) throw new Error(`Could not read ${what} for the sitemap.`);
  return rows;
}

/** Items the public can see. The anonymous client means row level security
 *  (`item_read_live`) leaves out a withdrawn item, the same as the gallery. */
export async function sitemapItems(): Promise<SitemapSource["items"]> {
  "use cache";
  cacheLife(LISTING_LIFE);
  cacheTag(TAGS.items);

  if (notConfigured()) return [];
  const supabase = createAnonClient();
  return all(
    await readAll<SitemapSource["items"][number]>((from, to) =>
      supabase.from("item").select("id,updated_at").order("created_at", { ascending: false }).order("id").range(from, to),
    ),
    "the items",
  );
}

/** Maps the hub publishes a page for. `public.map` is the table the map page
 *  reads a slug from, and the licence gate is the one `loadMapPage` applies, so
 *  a taken down map is not listed. */
export async function sitemapMaps(): Promise<SitemapSource["maps"]> {
  "use cache";
  cacheLife(LISTING_LIFE);
  cacheTag(TAGS.maps);

  if (notConfigured()) return [];
  const supabase = createAnonClient();
  const rows = all(
    await readAll<{ map_name: string; slug: string }>((from, to) =>
      supabase.from("map").select("map_name,slug").order("slug").range(from, to),
    ),
    "the maps",
  );

  const published = await fetchPublishedMapNames(
    createAdminClient(),
    rows.map((row) => row.map_name),
  );
  if (!published) throw new Error("Could not read the map licences for the sitemap.");
  return rows.filter((row) => published.has(row.map_name)).map((row) => ({ slug: row.slug }));
}

/** Games that are not hidden, with their live units and how many releases each
 *  holds. A game with two or more has a page of changes between them. Row level security
 *  (`game_read_visible`, `game_unit_read_visible`) leaves out a hidden game and
 *  its units. A unit a release retired (`removed_at`) is left out, as it is
 *  from the units grid by default. */
export async function sitemapGames(): Promise<SitemapSource["games"]> {
  "use cache";
  cacheLife(LISTING_LIFE);
  cacheTag(TAGS.games);

  if (notConfigured()) return [];
  const supabase = createAnonClient();
  const [games, units, versions] = await Promise.all([
    readAll<{ shortname: string }>((from, to) =>
      supabase.from("game").select("shortname").order("shortname").range(from, to),
    ),
    readAll<{ unit_name: string; game: { shortname: string } }>((from, to) =>
      supabase
        .from("game_unit")
        .select("unit_name,game!inner(shortname)")
        .is("removed_at", null)
        .order("game_id")
        .order("unit_name")
        .range(from, to),
    ),
    readAll<{ game: { shortname: string } }>((from, to) =>
      supabase
        .from("game_version")
        .select("game!inner(shortname)")
        .order("game_id")
        .order("id")
        .range(from, to),
    ),
  ]);

  const releases = new Map<string, number>();
  for (const version of all(versions, "the releases")) {
    releases.set(version.game.shortname, (releases.get(version.game.shortname) ?? 0) + 1);
  }
  const byGame = new Map<string, string[]>();
  for (const game of all(games, "the games")) byGame.set(game.shortname, []);
  for (const unit of all(units, "the units")) byGame.get(unit.game.shortname)?.push(unit.unit_name);
  return [...byGame].map(([shortname, held]) => ({
    shortname,
    units: held,
    releases: releases.get(shortname) ?? 0,
  }));
}

import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * The two things a person does to one map by hand (issue #193): read the
 * disagreements clients have reported about it, and put the tags on it that no
 * measurement produces.
 *
 * They are unrelated jobs that happen to be about the same table, and they are
 * in one file because they are both small and both only ever read by
 * `/moderation/maps`. Nothing here decides anything on a moderator's behalf.
 *
 * ## The conflicts are read with the secret key
 *
 * `public.map_source_conflict` grants `anon` and `authenticated` nothing and
 * carries row level security with no policy at all, which is the line
 * `asset_source_conflict` drew first: a reported hash names facts nobody has
 * checked, and who reported them is not the public's business. #193 says
 * outright that this reads through the admin client rather than by widening a
 * grant.
 *
 * The catalog itself is the opposite. `public.map` and `public.map_listing` are
 * readable by anybody, so everything on the map's own moderation view is read
 * with the moderator's own session and the secret key never comes near it.
 *
 * ## Nothing here is automatic
 *
 * `lib/assets/sourceConflict.ts` carries the reasoning for why the picture
 * equivalent has nothing automated behind it, and it holds here for the same
 * reasons. A conflict means two installs hold different bytes under one
 * canonical name, which is a corrupt or modified install rather than a new
 * release, and which of the two readings is the odd one out is a judgement made
 * by looking at the map. So the queue shows what was reported and offers one
 * action, and there is no way to act on a page of conflicts at once, because
 * clearing a map without reading its reports is the mistake worth making
 * impossible rather than convenient.
 */

/**
 * How many reports one queue shows.
 *
 * A conflict is rare and each one is read rather than skimmed, so this is a
 * ceiling on a page rather than a page size anybody is expected to fill. It also
 * bounds the second read below, which asks for the maps these reports name.
 */
export const CONFLICT_PAGE_SIZE = 200;

interface ConflictRow {
  id: number;
  map_id: string;
  source_archive: string;
  held_source_hash: string;
  reported_source_hash: string;
  reported_by: string | null;
  at: string;
}

interface MapRow {
  id: string;
  map_name: string;
  slug: string;
}

/** One report: what the hub held, what the client declared instead, who said so
 *  and when. Every field the issue asks the queue to show. */
export interface ReportedSource {
  id: number;
  sourceArchive: string;
  heldSourceHash: string;
  reportedSourceHash: string;
  /** Null on a report from an account that has since closed, which
   *  `map_source_conflict.reported_by` allows on purpose: the account most
   *  likely to close in a hurry is the one behind an anomaly. */
  reportedBy: string | null;
  at: string;
}

/** One map, with everything reported about it. */
export interface ConflictedMap {
  id: string;
  mapName: string;
  slug: string;
  /** Newest first, so what is happening now is at the top of each map. */
  reports: ReportedSource[];
}

/**
 * Reports grouped under the maps they are about, the maps with the most reports
 * first.
 *
 * The ordering is the whole reason to group rather than list. One report is an
 * install that has gone wrong somewhere. The same map collecting reports from
 * account after account is the case worth reading, and a flat list ordered by
 * time buries it among single reports about other maps.
 *
 * A report naming a map the second read did not return is dropped. That is a map
 * cleared between the two queries, and a row with no map to name is nothing a
 * moderator can act on.
 */
export function groupConflicts(rows: ConflictRow[], maps: MapRow[]): ConflictedMap[] {
  const named = new Map(maps.map((map) => [map.id, map]));
  const grouped = new Map<string, ConflictedMap>();

  for (const row of rows) {
    const map = named.get(row.map_id);
    if (!map) continue;

    const existing = grouped.get(map.id) ?? {
      id: map.id,
      mapName: map.map_name,
      slug: map.slug,
      reports: [],
    };

    existing.reports.push({
      id: row.id,
      sourceArchive: row.source_archive,
      heldSourceHash: row.held_source_hash,
      reportedSourceHash: row.reported_source_hash,
      reportedBy: row.reported_by,
      at: row.at,
    });

    grouped.set(map.id, existing);
  }

  return [...grouped.values()].sort((left, right) => right.reports.length - left.reports.length);
}

/**
 * Every map two clients have disagreed about, newest report first.
 *
 * Two reads rather than one embedded select, which is the reading
 * `lib/assets/sourceConflict.ts` takes of the same shape: an embedded select
 * depends on PostgREST having noticed the foreign key, and a schema cache that
 * has not reloaded turns the whole queue into an error rather than a page.
 *
 * Wants a client that bypasses row level security. See the note at the top of
 * this file, and `lib/supabase/admin.ts`.
 */
export async function fetchMapConflicts(
  supabase: SupabaseClient,
  limit = CONFLICT_PAGE_SIZE,
): Promise<ConflictedMap[]> {
  const { data } = await supabase
    .from("map_source_conflict")
    .select("id, map_id, source_archive, held_source_hash, reported_source_hash, reported_by, at")
    .order("at", { ascending: false })
    .limit(limit);

  const rows = (data ?? []) as unknown as ConflictRow[];
  if (rows.length === 0) return [];

  const { data: maps } = await supabase
    .from("map")
    .select("id, map_name, slug")
    .in("id", [...new Set(rows.map((row) => row.map_id))]);

  return groupConflicts(rows, (maps ?? []) as unknown as MapRow[]);
}

/**
 * Forget everything the hub holds about one map, so the next submission stores
 * it fresh.
 *
 * The one action the queue offers, and the only one worth having. While the held
 * facts are the good ones a conflict needs no action at all: the hub is already
 * refusing the modified install, which is what it is for. When the held facts are
 * the wrong ones the map is stuck, because the held hash is compared first and
 * nothing moves it, and every honest client is refused forever.
 *
 * `public.clear_map_facts` is where the decision and the reasoning live, and it
 * refuses a map nobody has recorded a disagreement about, so this is not a way
 * to delete a map. Comes back false when it refused, which the page shows by
 * simply still listing the map.
 */
export async function clearMapFacts(
  supabase: SupabaseClient,
  mapId: string,
): Promise<boolean> {
  const { data } = await supabase.rpc("clear_map_facts", { p_map_id: mapId });

  return data === true;
}

/**
 * How many curated tags one map may carry, and how long each may be.
 *
 * Both are bounds on a free text field rather than a view about what a tag
 * should be. `asymmetric`, `1v1` and `chokepoint` are the kind of thing the
 * column is for, and writing that list down would mean a moderator could not add
 * the word they need without a deploy.
 */
export const CURATED_TAG_LIMIT = 20;

export const CURATED_TAG_MAX_LENGTH = 64;

/**
 * The tags a submitted field means.
 *
 * Comma separated, because that is how somebody writes a short list and because
 * the alternative is a row of inputs that has to grow a button to add one.
 *
 * Lower cased, since the derived tags are lower case and `lib/maps/query.ts`
 * lower cases a tag filter before it matches. A curated `Asymmetric` would sit
 * in the listing as a tag no link could reach.
 *
 * The moderator's own order is kept, minus duplicates. Nothing downstream
 * depends on it, because `public.map_listing` sorts and deduplicates the merged
 * array anyway, so the only thing the order affects is what the field says when
 * the page is loaded again.
 */
export function parseCuratedTags(value: string): string[] {
  const seen = new Set<string>();

  for (const part of value.split(",")) {
    const tag = part.trim().toLowerCase();
    // A tag longer than a phrase is a paste that went wrong, and the column has
    // no constraint of its own to catch it.
    if (tag === "" || tag.length > CURATED_TAG_MAX_LENGTH) continue;
    seen.add(tag);
    if (seen.size === CURATED_TAG_LIMIT) break;
  }

  return [...seen];
}

/** What the field shows for the tags a map already carries, which has to read
 *  back through {@link parseCuratedTags} unchanged. */
export function curatedTagsField(tags: string[]): string {
  return tags.join(", ");
}

/**
 * Put a maintainer's tags on a map.
 *
 * `service_role` has held update on `public.map` since the catalog was written,
 * so this needs no new grant and no function behind it. It is one column and one
 * row, and the caller has already established that the caller is a moderator.
 *
 * Ingest never writes this column, which is what makes the work worth doing: a
 * client resubmitting the map at a newer catalog version replaces every measured
 * fact on the row and leaves these alone.
 */
export async function setCuratedTags(
  supabase: SupabaseClient,
  mapId: string,
  tags: string[],
): Promise<boolean> {
  const { error } = await supabase.from("map").update({ curated_tags: tags }).eq("id", mapId);

  return !error;
}

/** One map as its moderation view shows it. */
export interface ModeratedMap {
  id: string;
  mapName: string;
  slug: string;
  displayName: string | null;
  /** What a maintainer put there, which is what the form edits. */
  curatedTags: string[];
  /** What the measurements come to, off `public.map_listing` and never worked
   *  out here. The migration for that view says why a second copy of the
   *  thresholds is the failure to avoid. */
  derivedTags: string[];
}

/**
 * One map, as the moderation view needs it.
 *
 * Read with the visitor's own client, because every row behind it is public. The
 * two reads go together: the row carries the curated tags and the view carries
 * the merged array, and the difference between them is the derived half, which
 * the form must not offer to edit.
 */
export async function fetchModeratedMap(
  supabase: SupabaseClient,
  slug: string,
): Promise<ModeratedMap | null> {
  const [row, listing] = await Promise.all([
    supabase.from("map").select("id, map_name, slug, display_name, curated_tags").eq("slug", slug).maybeSingle(),
    supabase.from("map_listing").select("tags").eq("slug", slug).maybeSingle(),
  ]);

  if (!row.data) return null;

  const map = row.data as unknown as {
    id: string;
    map_name: string;
    slug: string;
    display_name: string | null;
    curated_tags: string[];
  };

  const tags = ((listing.data as unknown as { tags: string[] } | null)?.tags ?? []).filter(
    (tag) => !map.curated_tags.includes(tag),
  );

  return {
    id: map.id,
    mapName: map.map_name,
    slug: map.slug,
    displayName: map.display_name,
    curatedTags: map.curated_tags,
    derivedTags: tags,
  };
}

/** How many maps a search offers. Short on purpose: this is how a moderator
 *  reaches one map's view, not a second listing page. */
export const MAP_SEARCH_LIMIT = 20;

export interface MapMatch {
  mapName: string;
  slug: string;
}

/**
 * Maps whose canonical name contains what was typed.
 *
 * The way a moderator reaches the curated tags form for a map nobody has
 * disagreed about, which is nearly all of them. Without it the form is a URL
 * somebody has to build by hand from a slug they cannot see.
 *
 * A contains match on the canonical name rather than the free text search
 * `/maps` runs. The reader here knows which map they want and has its name in
 * front of them, so ranked search over descriptions and authors would put the
 * answer below maps that merely mention it.
 */
export async function searchMaps(
  supabase: SupabaseClient,
  term: string,
): Promise<MapMatch[]> {
  const wanted = term.trim();
  if (wanted === "") return [];

  const { data } = await supabase
    .from("map")
    .select("map_name, slug")
    // Percent and underscore are wildcards to `like`, so a name carrying either
    // would match more than it should. Escaped rather than refused, because an
    // underscore is ordinary in a map name.
    .ilike("map_name", `%${wanted.replace(/[\\%_]/g, "\\$&")}%`)
    .order("map_name")
    .limit(MAP_SEARCH_LIMIT);

  const rows = (data ?? []) as unknown as { map_name: string; slug: string }[];

  return rows.map((row) => ({ mapName: row.map_name, slug: row.slug }));
}

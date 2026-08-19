import { expect, mock, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * The map half of what a moderator does to the catalog by hand (issue #193).
 *
 * Two claims are worth a test here. The first is that nobody but a moderator
 * gets anything from any of the three routes, which is asserted against every
 * one of them rather than against the one that happened to be written first: an
 * access check is not a property of the feature, it is a line in each file, and
 * a page added without it looks exactly like a page with it.
 *
 * The second is the ordering and the parsing, both of which fail quietly. A
 * queue ordered by time buries the map collecting report after report among
 * single reports about other maps, which is the one case the queue exists to
 * surface. A tag parsed with its case left on sits in the listing where no link
 * can reach it, because every tag filter is lower cased before it matches.
 *
 * The database side is `supabase/tests/map_moderation.test.sql`, which proves
 * what clearing a map does to a real submission and that a curated tag survives
 * a re-ingest.
 */

/** Nobody, whatever they ask for. `is_moderator()` is the only call any of the
 *  three pages makes before it decides. */
mock.module("@/lib/supabase/server", () => ({
  createClient: async () => ({
    rpc: async () => ({ data: false, error: null }),
  }),
}));

/** Reached only after the check passes, so a page that called it would be a page
 *  that had already let a stranger through. */
mock.module("@/lib/supabase/admin", () => ({
  createAdminClient: () => {
    throw new Error("a moderation page must decide who is asking before it reads anything");
  },
}));

const { curatedTagsField, groupConflicts, parseCuratedTags, searchMaps } = await import(
  "./moderation"
);

const conflicts = (await import("@/app/moderation/maps/page")).default;
const curatedTags = (await import("@/app/moderation/maps/[slug]/page")).default;
const authors = (await import("@/app/moderation/authors/page")).default;

function report(id: number, mapId: string, at: string) {
  return {
    id,
    map_id: mapId,
    source_archive: "comet.sd7",
    held_source_hash: "src-held",
    reported_source_hash: `src-reported-${id}`,
    reported_by: null,
    at,
  };
}

const MAPS = [
  { id: "map-one", map_name: "Comet Catcher 1.8", slug: "comet-catcher-1-8" },
  { id: "map-two", map_name: "Tangerine 1.1", slug: "tangerine-1-1" },
];

/**
 * The map somebody keeps disagreeing about is the one worth reading, and a flat
 * list ordered by time puts a single fresh report above it.
 */
test("the most reported map is first, whoever reported last", () => {
  const grouped = groupConflicts(
    [
      report(1, "map-two", "2026-08-19T12:00:00Z"),
      report(2, "map-one", "2026-08-19T11:00:00Z"),
      report(3, "map-one", "2026-08-19T10:00:00Z"),
    ],
    MAPS,
  );

  expect(grouped.map((map) => map.mapName)).toEqual(["Comet Catcher 1.8", "Tangerine 1.1"]);
  expect(grouped[0].reports.map((held) => held.id)).toEqual([2, 3]);
});

/** A map cleared between the two reads. There is nothing to name and nothing to
 *  act on, so the report is dropped rather than rendered against no map. */
test("a report about a map that is no longer held is dropped", () => {
  expect(groupConflicts([report(1, "map-gone", "2026-08-19T12:00:00Z")], MAPS)).toEqual([]);
});

/**
 * Lower case because every tag filter is lower cased before it matches, so a
 * curated `Asymmetric` would sit in the listing as a tag no link could reach.
 */
test("a tag is folded the way a tag filter is folded", () => {
  expect(parseCuratedTags("Asymmetric, 1v1 , CHOKEPOINT")).toEqual([
    "asymmetric",
    "1v1",
    "chokepoint",
  ]);
});

test("an empty field is no tags rather than one tag with no name", () => {
  expect(parseCuratedTags("")).toEqual([]);
  expect(parseCuratedTags(" , ,")).toEqual([]);
});

test("the same tag twice is the same tag", () => {
  expect(parseCuratedTags("1v1, 1v1")).toEqual(["1v1"]);
});

/** A paste that went wrong. The column has no constraint of its own, so this is
 *  the only thing standing between a stray document and the catalog. */
test("a tag longer than a phrase is dropped rather than stored", () => {
  expect(parseCuratedTags(`asymmetric, ${"a".repeat(65)}`)).toEqual(["asymmetric"]);
});

test("the field a map already carries reads back unchanged", () => {
  const tags = ["asymmetric", "1v1"];

  expect(parseCuratedTags(curatedTagsField(tags))).toEqual(tags);
});

/** Enough of PostgREST for one filtered, ordered, limited read. */
function fakeSearch(): { supabase: SupabaseClient; pattern: () => string } {
  let pattern = "";
  const builder = {
    select: () => builder,
    ilike: (_column: string, value: string) => {
      pattern = value;
      return builder;
    },
    order: () => builder,
    limit: () => Promise.resolve({ data: [], error: null }),
  };

  return {
    supabase: { from: () => builder } as unknown as SupabaseClient,
    pattern: () => pattern,
  };
}

/**
 * `%` and `_` are wildcards to `like`, and map names carry both. Without the
 * escape a moderator searching for `comet_catcher` would match every name with
 * any character in that position, and the map they wanted would be one line
 * among many.
 */
test("a wildcard in a map name is searched for rather than obeyed", async () => {
  const { supabase, pattern } = fakeSearch();
  await searchMaps(supabase, "comet_catcher 100%");

  expect(pattern()).toBe("%comet\\_catcher 100\\%%");
});

test("an empty search asks the database nothing", async () => {
  const { supabase, pattern } = fakeSearch();

  expect(await searchMaps(supabase, "   ")).toEqual([]);
  expect(pattern()).toBe("");
});

/**
 * What `notFound()` throws.
 *
 * Asserted rather than "it threw something", and the difference is the whole
 * point of the test below. Every one of these pages would also throw with the
 * check taken out, because the client mocked above answers `is_moderator()` and
 * nothing else, so a test that accepted any error would pass against a page that
 * had let the stranger through and then fallen over reading the catalog.
 *
 * The digest is Next's own and is not exported. If a version changes it this
 * fails loudly, which is the right way round for the one assertion standing
 * between a stranger and the moderation pages.
 */
async function isNotFound(rendering: Promise<unknown>): Promise<void> {
  const thrown = await rendering.then(
    () => null,
    (error: unknown) => error,
  );

  expect((thrown as { digest?: string } | null)?.digest).toBe("NEXT_HTTP_ERROR_FALLBACK;404");
}

/**
 * The one that matters, against all three routes.
 *
 * `notFound()` rather than a 403, which is the answer every moderation page in
 * the hub gives: whether the page exists is not something a stranger needs to
 * learn.
 */
test("a non moderator gets nothing from any of the three routes", async () => {
  await isNotFound(conflicts({ searchParams: Promise.resolve({}) } as never));

  await isNotFound(
    curatedTags({ params: Promise.resolve({ slug: "comet-catcher-1-8" }) } as never),
  );

  await isNotFound(authors({ searchParams: Promise.resolve({}) } as never));
});

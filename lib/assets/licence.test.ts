import { expect, test } from "bun:test";
import { readdirSync, readFileSync } from "node:fs";
import {
  ASSET_REDISTRIBUTION_STATES,
  licenceForMap,
  mayRedistribute,
  type AssetLicenceRow,
} from "./licence";

/**
 * The same two-copies problem `asset.test.ts` has: the permission vocabulary is
 * a check constraint in the migration and a literal here, and a value added to
 * one and not the other is accepted by every line of TypeScript and refused by
 * the insert.
 */
function listTheDatabaseAccepts(column: string): string[] {
  const dir = "supabase/migrations";
  const pattern = new RegExp(`${column} in \\(([^)]*)\\)`);
  const constraint = readdirSync(dir)
    .sort()
    .map((file) => readFileSync(`${dir}/${file}`, "utf8"))
    .filter((sql) => pattern.test(sql))
    .at(-1);
  const list = constraint?.match(pattern)?.[1] ?? "";
  return [...list.matchAll(/'([^']+)'/g)].map((match) => match[1]);
}

test("the database accepts exactly the redistribution states the hub knows about", () => {
  expect(listTheDatabaseAccepts("redistribute_extracted").sort()).toEqual(
    [...ASSET_REDISTRIBUTION_STATES].sort(),
  );
  expect(listTheDatabaseAccepts("redistribute_rendered").sort()).toEqual(
    [...ASSET_REDISTRIBUTION_STATES].sort(),
  );
});

/**
 * `BYAR`, not `BAR`. The shortname comes from the archive's modinfo and nothing
 * else (`lib/gallery/publish.ts:81`), Beyond All Reason's modinfo says `BYAR`,
 * and the row 20260814150100 seeds is keyed that way. A fixture spelling it the
 * other way is the kind of invention somebody later reads as a second game.
 */
function row(over: Partial<AssetLicenceRow> = {}): AssetLicenceRow {
  return {
    id: "00000000-0000-0000-0000-000000000000",
    game: "BYAR",
    map_name: null,
    all_maps: null,
    licence: "MIT",
    licence_url: "https://example.test/licence",
    notes: null,
    decision: null,
    decided_at: null,
    checked_at: "2026-08-14T00:00:00Z",
    checked_by: "test",
    redistribute_extracted: "unknown",
    redistribute_rendered: "unknown",
    created_at: "2026-08-14T00:00:00Z",
    updated_at: "2026-08-14T00:00:00Z",
    ...over,
  };
}

/** The one row that answers for every map without a row of its own. */
function blanketMapRow(over: Partial<AssetLicenceRow> = {}): AssetLicenceRow {
  return row({
    id: "00000000-0000-0000-0000-0000000000ff",
    game: null,
    all_maps: true,
    licence: null,
    licence_url: null,
    decision: "maintainer decision, 2026-08-14",
    decided_at: "2026-08-14T00:00:00Z",
    redistribute_extracted: "allowed",
    redistribute_rendered: "allowed",
    ...over,
  });
}

/**
 * Nobody having looked is not a finding, so it reads as no rather than as yes.
 * That is a reading of the record and not a refusal of a request: since #167
 * nothing on a write path asks, and an unresearched subject uploads normally.
 */
test("a subject nobody has ruled on answers no", () => {
  expect(mayRedistribute(null, "extracted")).toBe(false);
  expect(mayRedistribute(undefined, "rendered")).toBe(false);
});

test("recording a licence is not the same as permitting redistribution", () => {
  expect(mayRedistribute(row(), "extracted")).toBe(false);
  expect(mayRedistribute(row(), "rendered")).toBe(false);
});

test("a refusal reads the same way an undecided row does", () => {
  const refused = row({ redistribute_extracted: "denied", redistribute_rendered: "denied" });
  expect(mayRedistribute(refused, "extracted")).toBe(false);
  expect(mayRedistribute(refused, "rendered")).toBe(false);
});

/**
 * The distinction the issue turns on. A render is a derivative work drawn from
 * the model, and permission to pass the shipped buildpic on does not always
 * cover it.
 */
test("permission to redistribute extracted images does not permit renders", () => {
  const extractionOnly = row({ redistribute_extracted: "allowed" });
  expect(mayRedistribute(extractionOnly, "extracted")).toBe(true);
  expect(mayRedistribute(extractionOnly, "rendered")).toBe(false);
});

test("and permission to publish renders does not permit extraction", () => {
  const rendersOnly = row({ redistribute_rendered: "allowed" });
  expect(mayRedistribute(rendersOnly, "extracted")).toBe(false);
  expect(mayRedistribute(rendersOnly, "rendered")).toBe(true);
});

/**
 * Maps (issue #121). There is no central licence for Recoil maps and no field
 * anywhere that could carry one, so almost every map arrives with no row of its
 * own and the maintainer's answer was a blanket default. These four tests are
 * the whole of that behaviour.
 */
test("a map with no row of its own falls back to the blanket map row", () => {
  const resolved = licenceForMap(undefined, blanketMapRow());
  expect(mayRedistribute(resolved, "extracted")).toBe(true);
  expect(mayRedistribute(resolved, "rendered")).toBe(true);
});

test("a map's own row wins over the blanket one", () => {
  const perMap = row({
    game: null,
    map_name: "Comet Catcher Remake 1.8",
    redistribute_extracted: "allowed",
  });
  const resolved = licenceForMap(perMap, blanketMapRow());
  expect(resolved).toBe(perMap);
  expect(mayRedistribute(resolved, "rendered")).toBe(false);
});

/**
 * The reason the default is a row rather than a constant in this file. Taking
 * one map back out has to be possible without touching the default, and a
 * refusal has to beat it rather than merge with it.
 */
test("a map refused by name stays refused despite the blanket row", () => {
  const refused = row({
    game: null,
    map_name: "Comet Catcher Remake 1.8",
    redistribute_extracted: "denied",
    redistribute_rendered: "denied",
  });
  const resolved = licenceForMap(refused, blanketMapRow());
  expect(mayRedistribute(resolved, "extracted")).toBe(false);
  expect(mayRedistribute(resolved, "rendered")).toBe(false);
});

/**
 * The default is data, so it can be absent, and absent has to mean no. A
 * database missing the blanket row records nothing about maps rather than
 * everything.
 */
test("without the blanket row a map answers no", () => {
  expect(mayRedistribute(licenceForMap(undefined, undefined), "extracted")).toBe(false);
  expect(mayRedistribute(licenceForMap(null, null), "rendered")).toBe(false);
});

import { expect, test } from "bun:test";
import { readdirSync, readFileSync } from "node:fs";
import {
  ASSET_REDISTRIBUTION_STATES,
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

function row(over: Partial<AssetLicenceRow> = {}): AssetLicenceRow {
  return {
    id: "00000000-0000-0000-0000-000000000000",
    game: "BAR",
    map_name: null,
    licence: "MIT",
    licence_url: "https://example.test/licence",
    notes: null,
    checked_at: "2026-08-14T00:00:00Z",
    checked_by: "test",
    redistribute_extracted: "unknown",
    redistribute_rendered: "unknown",
    created_at: "2026-08-14T00:00:00Z",
    updated_at: "2026-08-14T00:00:00Z",
    ...over,
  };
}

/**
 * The durable tier is a public git repository, so the cost of these two answers
 * is not symmetric. A wrong no is a missing picture. A wrong yes is a permanent
 * publication somebody has to be asked to remove.
 */
test("a subject nobody has ruled on publishes nothing", () => {
  expect(mayRedistribute(null, "extracted")).toBe(false);
  expect(mayRedistribute(undefined, "rendered")).toBe(false);
});

test("recording a licence is not the same as permitting redistribution", () => {
  expect(mayRedistribute(row(), "extracted")).toBe(false);
  expect(mayRedistribute(row(), "rendered")).toBe(false);
});

test("a refusal blocks the same way an undecided row does", () => {
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

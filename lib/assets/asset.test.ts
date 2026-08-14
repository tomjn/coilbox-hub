import { expect, test } from "bun:test";
import { readdirSync, readFileSync } from "node:fs";
import {
  ASSET_APPROVAL_SOURCES,
  ASSET_EVENT_ACTIONS,
  ASSET_MODERATION_STATES,
  ASSET_ORIGINS,
  ASSET_REJECTION_KINDS,
  ASSET_TIERS,
  MAP_VARIANTS,
  MODERATOR_REJECTION_KINDS,
  UNIT_BUILDPIC_VARIANT,
  UNIT_RENDER_VARIANT_PREFIX,
} from "./asset";

/**
 * `public.asset` writes its vocabularies down as check constraints, so a value
 * added here and not there is accepted by every line of TypeScript and refused
 * by the insert. The pgTAP suite proves the constraints hold. These prove the
 * two copies still say the same thing, which is the half neither suite can
 * check on its own.
 */

/** The last migration that constrains `column`, read for its literal list.
 * Anchored on a word boundary, so asking about `kind` does not read back the
 * list belonging to `rejection_kind`. */
function listTheDatabaseAccepts(column: string): string[] {
  const dir = "supabase/migrations";
  const pattern = new RegExp(`\\b${column} in \\(([^)]*)\\)`);
  const constraint = readdirSync(dir)
    .sort()
    .map((file) => readFileSync(`${dir}/${file}`, "utf8"))
    .filter((sql) => pattern.test(sql))
    .at(-1);
  const list = constraint?.match(pattern)?.[1] ?? "";
  return [...list.matchAll(/'([^']+)'/g)].map((match) => match[1]);
}

test("the database accepts exactly the tiers the hub knows about", () => {
  expect(listTheDatabaseAccepts("tier").sort()).toEqual([...ASSET_TIERS].sort());
});

test("the database accepts exactly the moderation states the hub knows about", () => {
  expect(listTheDatabaseAccepts("moderation").sort()).toEqual(
    [...ASSET_MODERATION_STATES].sort(),
  );
});

test("the database accepts exactly the origins the hub knows about", () => {
  expect(listTheDatabaseAccepts("origin").sort()).toEqual([...ASSET_ORIGINS].sort());
});

test("the database accepts exactly the approval sources the hub knows about", () => {
  expect(listTheDatabaseAccepts("approval_source").sort()).toEqual(
    [...ASSET_APPROVAL_SOURCES].sort(),
  );
});

test("the database accepts exactly the rejection kinds the hub knows about", () => {
  expect(listTheDatabaseAccepts("rejection_kind").sort()).toEqual(
    [...ASSET_REJECTION_KINDS].sort(),
  );
});

/**
 * `unrecorded` is the backfill for rows rejected before there were kinds, and
 * `reject_asset` refuses it. A grid that offered it would let a moderator
 * decide today and decline to say what they decided, which is the hole #115
 * closes.
 */
test("a moderator may only choose between the two real kinds", () => {
  expect([...MODERATOR_REJECTION_KINDS].sort()).toEqual(["editorial", "safety"]);
  expect(MODERATOR_REJECTION_KINDS as readonly string[]).not.toContain("unrecorded");
});

test("the database accepts exactly the audit actions the hub knows about", () => {
  expect(listTheDatabaseAccepts("action").sort()).toEqual([...ASSET_EVENT_ACTIONS].sort());
});

test("the database accepts exactly the map variants the hub knows about", () => {
  expect(listTheDatabaseAccepts("variant").sort()).toEqual([...MAP_VARIANTS].sort());
});

/**
 * The unit variant rule is a check rather than a list, since the angle on a
 * render is open ended. Read back the two halves it is built from.
 */
test("the unit variant rule is spelled the same way in both places", () => {
  const dir = "supabase/migrations";
  const constraint = readdirSync(dir)
    .sort()
    .map((file) => readFileSync(`${dir}/${file}`, "utf8"))
    .filter((sql) => sql.includes("asset_unit_variant_check"))
    .at(-1);

  expect(constraint).toBeDefined();
  expect(constraint).toContain(`variant = '${UNIT_BUILDPIC_VARIANT}'`);
  expect(constraint).toContain(`variant like '${UNIT_RENDER_VARIANT_PREFIX}%'`);
});

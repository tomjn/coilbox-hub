import { expect, test } from "bun:test";
import { readdirSync, readFileSync } from "node:fs";
import { USER_CAPABILITIES } from "./capability";

/**
 * The same two-copies problem `asset.test.ts` has. The capability vocabulary is
 * a check constraint in the migration and a literal here, and a capability
 * added to one and not the other is a grant TypeScript is happy to ask for and
 * the insert refuses, or worse, a name the app checks and nothing can ever
 * hold.
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

test("the database accepts exactly the capabilities the hub knows about", () => {
  expect(listTheDatabaseAccepts("capability").sort()).toEqual([...USER_CAPABILITIES].sort());
});

/**
 * Seeding content and waiving the moderation queue are separate grants, and the
 * failure the issue describes is a future edit quietly making one of them mean
 * the other. Two names, and neither is spelled `trusted`.
 */
test("seeding and publishing unreviewed are two capabilities, not one trusted flag", () => {
  expect(USER_CAPABILITIES).toContain("can_seed_unit_assets");
  expect(USER_CAPABILITIES).toContain("can_publish_unreviewed");
  expect(USER_CAPABILITIES.filter((name) => name.includes("trust"))).toEqual([]);
});

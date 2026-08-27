import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import {
  gameFactions,
  loadUnitGrid,
  parseUnitGridFilters,
  unbuildableUnits,
  unitBuilders,
} from "./units";

/**
 * Hiding retired units is a null check, and PostgREST spells that `is.null`
 * (#255). Asking it for `removed_at=eq.null` is refused, which took out the
 * whole encyclopedia rather than a single unit, and looked the same from the
 * page as a game with nothing in it.
 */

interface Row {
  unit_name: string;
  full_name: string | null;
  faction_key: string | null;
  removed_at: string | null;
}

const ROWS: Row[] = [
  { unit_name: "armcom", full_name: "Commander", faction_key: "arm", removed_at: null },
  { unit_name: "armsolar", full_name: "Solar Collector", faction_key: "arm", removed_at: null },
  { unit_name: "armbrawl", full_name: "Brawler", faction_key: "arm", removed_at: "2026-01-01" },
];

/**
 * A `game_unit` that answers about null the way PostgREST does: `is` is the
 * null check, and a null handed to `eq` is refused rather than matching
 * anything. That refusal is the whole of what this proves, so a fake that
 * quietly accepted it would pass while the site stayed broken.
 */
function fakeUnits(rows: Row[]): SupabaseClient {
  const build = (held: Row[], refused: string | null) => {
    const answer = (data: Row[] | null, count: number | null) => ({
      data: refused ? null : data,
      count: refused ? null : count,
      error: refused ? { message: refused } : null,
    });

    const builder = {
      select: () => build(held, refused),
      eq(column: string, value: unknown) {
        if (value === null) {
          return build(held, `"failed to parse filter (eq.null)" on ${column}`);
        }
        // The join filter narrows to one game, which this table already is.
        if (column === "game.shortname") return build(held, refused);
        return build(
          held.filter((row) => row[column as keyof Row] === value),
          refused,
        );
      },
      is(column: string, value: unknown) {
        return build(
          held.filter((row) => row[column as keyof Row] === value),
          refused,
        );
      },
      or: () => build(held, refused),
      order: () => build(held, refused),
      range: (from: number, to: number) =>
        Promise.resolve(answer(held.slice(from, to + 1), held.length)),
      then: (resolve: (value: unknown) => unknown) =>
        Promise.resolve(answer(held, held.length)).then(resolve),
    };
    return builder;
  };

  return { from: () => build(rows, null) } as unknown as SupabaseClient;
}

test("the grid hides retired units without being refused for it", async () => {
  const grid = await loadUnitGrid(
    fakeUnits(ROWS),
    "BA",
    parseUnitGridFilters({}),
  );

  expect(grid.error).toBeNull();
  expect(grid.units.map((u) => u.unit_name)).toEqual(["armcom", "armsolar"]);
});

test("asking for retired units shows the one that was removed", async () => {
  const grid = await loadUnitGrid(
    fakeUnits(ROWS),
    "BA",
    parseUnitGridFilters({ retired: "1" }),
  );

  expect(grid.error).toBeNull();
  expect(grid.units.map((u) => u.unit_name)).toEqual([
    "armcom",
    "armsolar",
    "armbrawl",
  ]);
});

test("a faction filter narrows the grid to that side (#258)", async () => {
  const rows: Row[] = [
    ...ROWS,
    { unit_name: "corcom", full_name: "Commander", faction_key: "core", removed_at: null },
    { unit_name: "corsolar", full_name: "Solar Collector", faction_key: "core", removed_at: null },
  ];
  const grid = await loadUnitGrid(fakeUnits(rows), "BA", parseUnitGridFilters({ faction: "core" }));

  expect(grid.error).toBeNull();
  expect(grid.units.map((u) => u.unit_name)).toEqual(["corcom", "corsolar"]);
});

test("the faction filter parses to null when nothing is chosen", () => {
  expect(parseUnitGridFilters({}).faction).toBeNull();
  expect(parseUnitGridFilters({ faction: "" }).faction).toBeNull();
  expect(parseUnitGridFilters({ faction: ["core"] }).faction).toBe("core");
});

/**
 * Unbuildable units (#280): nothing builds them and no start unit heads
 * them, which is what an archive's reference defs are. The exclusion list
 * rides into the grid query, so paging and the count stay honest.
 */

interface Referencing {
  unit_name: string;
  build_options: string[];
  removed_at: string | null;
}

function fakeCatalog(rows: Referencing[], startUnits: string[]): SupabaseClient {
  const build = (held: Referencing[]) => ({
    select: () => build(held),
    eq: () => build(held),
    is: (_column: string, value: unknown) =>
      Promise.resolve({
        data: held.filter((row) => row.removed_at === value),
        error: null,
      }),
  });
  const game = {
    select: () => game,
    eq: () => game,
    maybeSingle: () => Promise.resolve({ data: { start_units: startUnits }, error: null }),
  };
  return {
    from: (table: string) => (table === "game" ? game : build(rows)),
  } as unknown as SupabaseClient;
}

const CATALOG: Referencing[] = [
  { unit_name: "armcom", build_options: ["armsolar"], removed_at: null },
  { unit_name: "armsolar", build_options: [], removed_at: null },
  { unit_name: "armfark", build_options: [], removed_at: null },
  { unit_name: "oldref", build_options: [], removed_at: "2026-01-01" },
];

test("a unit nobody builds and no start unit heads is a ghost", async () => {
  const ghosts = await unbuildableUnits(fakeCatalog(CATALOG, ["armcom"]), "BA");

  // armfark is alive but unreachable and unbuilt; oldref does not count,
  // since it retired already.
  expect(ghosts).toEqual(["armfark"]);
});

test("a start unit never hides, however lonely it is", async () => {
  const ghosts = await unbuildableUnits(fakeCatalog(CATALOG, ["armcom", "armfark"]), "BA");
  expect(ghosts).toEqual([]);
});

/**
 * Who builds a unit: the reverse of the build options a unit's page already
 * lists. The edge lives on the builder's row, so the answer is a containment
 * read across the game's units rather than anything the unit itself holds.
 */

interface BuilderRow {
  unit_name: string;
  full_name: string | null;
  removed_at: string | null;
  build_options: string[];
  game_unit_revision: { version: string; full_name: string | null; build_options: string[] }[];
}

const BUILDERS: BuilderRow[] = [
  {
    unit_name: "armlab",
    full_name: "Kbot Lab",
    removed_at: null,
    build_options: ["armck", "armpw"],
    game_unit_revision: [
      { version: "1.0", full_name: "Kbot Factory", build_options: ["armck"] },
      { version: "2.0", full_name: "Kbot Lab", build_options: ["armck", "armpw"] },
    ],
  },
  {
    unit_name: "armcom",
    full_name: "Commander",
    removed_at: null,
    build_options: ["armlab", "armsolar"],
    game_unit_revision: [{ version: "2.0", full_name: "Commander", build_options: ["armlab"] }],
  },
  {
    unit_name: "armshed",
    full_name: "Kbot Shed",
    removed_at: "2026-01-01",
    build_options: ["armck"],
    game_unit_revision: [{ version: "1.0", full_name: "Kbot Shed", build_options: ["armck"] }],
  },
];

/**
 * A `game_unit` that filters the way PostgREST does for the two reads
 * {@link unitBuilders} makes: `cs` on the row's own array, and `cs` on an inner
 * joined revision's array once `version` has narrowed it.
 */
function fakeBuilders(rows: BuilderRow[]): SupabaseClient {
  const build = (held: BuilderRow[]) => {
    const builder = {
      select: () => build(held),
      eq(column: string, value: unknown) {
        if (column === "game.shortname") return build(held);
        if (column === "game_unit_revision.version") {
          // An inner join keeps only the rows with a revision for the version,
          // and narrows the embedded array to it.
          return build(
            held
              .map((row) => ({
                ...row,
                game_unit_revision: row.game_unit_revision.filter((r) => r.version === value),
              }))
              .filter((row) => row.game_unit_revision.length > 0),
          );
        }
        throw new Error(`unexpected eq on ${column}`);
      },
      is: (column: string, value: unknown) =>
        build(held.filter((row) => row[column as keyof BuilderRow] === value)),
      contains(column: string, value: unknown) {
        const wanted = value as string[];
        const options = (row: BuilderRow) =>
          column === "build_options"
            ? row.build_options
            : row.game_unit_revision.flatMap((r) => r.build_options);
        return build(held.filter((row) => wanted.every((w) => options(row).includes(w))));
      },
      then: (resolve: (value: unknown) => unknown) =>
        Promise.resolve({ data: held, error: null }).then(resolve),
    };
    return builder;
  };
  return { from: () => build(rows) } as unknown as SupabaseClient;
}

test("a unit's builders are the units holding it as a build option", async () => {
  expect(await unitBuilders(fakeBuilders(BUILDERS), "BA", "armlab")).toEqual([
    { name: "armcom", label: "Commander" },
  ]);
});

test("a retired builder is not a live path to a unit", async () => {
  // armshed builds armck too, but it retired, so only the lab counts.
  expect(await unitBuilders(fakeBuilders(BUILDERS), "BA", "armck")).toEqual([
    { name: "armlab", label: "Kbot Lab" },
  ]);
});

test("a release answers with that release's builders, under that release's names", async () => {
  // In 1.0 the lab was called a factory and did not build armpw yet, and it
  // had not retired, so the shed still counts.
  expect(await unitBuilders(fakeBuilders(BUILDERS), "BA", "armck", "1.0")).toEqual([
    { name: "armlab", label: "Kbot Factory" },
    { name: "armshed", label: "Kbot Shed" },
  ]);
  expect(await unitBuilders(fakeBuilders(BUILDERS), "BA", "armpw", "1.0")).toEqual([]);
});

test("a read that fails answers with nothing rather than taking the page down", async () => {
  const refused = {
    select: () => refused,
    eq: () => refused,
    is: () => refused,
    contains: () => refused,
    then: (resolve: (value: unknown) => unknown) =>
      Promise.resolve({ data: null, error: { message: "nope" } }).then(resolve),
  };
  const supabase = { from: () => refused } as unknown as SupabaseClient;

  expect(await unitBuilders(supabase, "BA", "armlab")).toEqual([]);
});

/**
 * A side called Random is the die roll a lobby offers, not an army (#280).
 * No list offers it beside real sides, whatever casing it arrived in.
 */
test("the random side hides from every faction list", async () => {
  const rows = [
    { key: "arm", name: "Arm" },
    { key: "random", name: "Random" },
    { key: "core", name: "Random" },
    { key: "random", name: "Trolls" },
  ];
  const factionQuery = {
    select: () => factionQuery,
    eq: () => factionQuery,
    then: (resolve: (value: unknown) => unknown) =>
      Promise.resolve({ data: rows, error: null }).then(resolve),
  };
  const supabase = { from: () => factionQuery } as unknown as SupabaseClient;

  expect(await gameFactions(supabase, "BA")).toEqual([{ key: "arm", name: "Arm" }]);
});

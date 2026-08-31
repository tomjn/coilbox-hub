import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { UNIT_RENDER_ANGLES } from "@/lib/assets/asset";
import {
  gameFactions,
  loadUnitGrid,
  parseUnitGridFilters,
  loadUnitPage,
  loadUnitStages,
  morphedAwayUnits,
  unbuildableUnits,
  unitBuilders,
  unitRenders,
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

/**
 * The `asset` table as far as a picture read is concerned. The identity filter
 * is recorded rather than applied, the way `lib/assets/resolve.test.ts` does it:
 * what matters here is which angles were asked for and in how many round trips.
 */
function fakeAssets(rows: Record<string, unknown>[], filters: string[]): SupabaseClient {
  const from = () => ({
    select: () => ({
      eq: () => ({
        or: (filter: string) => {
          filters.push(filter);
          return Promise.resolve({ data: rows, error: null });
        },
      }),
    }),
  });

  return { from } as unknown as SupabaseClient;
}

function renderRow(angle: string) {
  return {
    game: "BA",
    unit_name: "armcom",
    map_name: null,
    variant: `render:${angle}`,
    tier: "static",
    path: `units/BA/render-${angle}/abc.webp`,
    width: 256,
    height: 256,
    moderation: "approved",
  };
}

/**
 * Coilbox renders four angles and the page used to ask for the top one alone,
 * so the other three sat in the store with nothing to draw them.
 */
test("a unit's renders cover every angle the vocabulary names", async () => {
  const filters: string[] = [];
  const renders = await unitRenders(
    fakeAssets([renderRow("top"), renderRow("side")], filters),
    "BA",
    "armcom",
  );

  expect(renders.map((entry) => entry.angle)).toEqual([...UNIT_RENDER_ANGLES]);
  for (const angle of UNIT_RENDER_ANGLES) {
    expect(filters.join(" ")).toContain(`render:${angle}`);
  }
});

test("every angle resolves in one read rather than one each", async () => {
  const filters: string[] = [];
  await unitRenders(fakeAssets([], filters), "BA", "armcom");

  expect(filters).toHaveLength(1);
});

test("an angle the hub holds is served and one it does not is a placeholder", async () => {
  const renders = await unitRenders(
    fakeAssets([renderRow("top"), renderRow("side")], []),
    "BA",
    "armcom",
  );
  const by = new Map(renders.map((entry) => [entry.angle, entry.asset]));

  expect(by.get("side")?.from).toBe("static");
  expect(by.get("front")?.from).toBe("placeholder");
});

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
      build(held.filter((row) => row.removed_at === value)),
    order: () => build([...held].sort((a, b) => a.unit_name.localeCompare(b.unit_name))),
    range: (from: number, to: number) =>
      Promise.resolve({ data: held.slice(from, to + 1), error: null }),
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
 * A morph chain is one cell (#295). Every level but the base joins the same
 * exclusion list the ghosts ride, so the grid's paging and its count are
 * computed over what it actually shows.
 */

interface Morphing {
  unit_name: string;
  morph_targets: unknown;
  removed_at: string | null;
}

/**
 * A `game_unit` that caps a response the way PostgREST's `max_rows` does, so a
 * read that trusted one request to carry a whole game comes back short here
 * rather than in production. `cap` is the fake's own limit, well below the real
 * 1000, because the point is that the reader pages rather than what the number
 * is.
 */
function fakeMorphs(rows: Morphing[], cap = 1000): SupabaseClient {
  const build = (held: Morphing[]) => ({
    select: () => build(held),
    eq: () => build(held),
    is: (_column: string, value: unknown) =>
      build(held.filter((row) => row.removed_at === value)),
    order: () => build([...held].sort((a, b) => a.unit_name.localeCompare(b.unit_name))),
    range: (from: number, to: number) =>
      Promise.resolve({
        data: held.slice(from, Math.min(to + 1, from + cap)),
        error: null,
      }),
  });
  return { from: () => build(rows) } as unknown as SupabaseClient;
}

const MORPHING: Morphing[] = [
  { unit_name: "ArmCom1", morph_targets: [{ into: "armcom2" }], removed_at: null },
  { unit_name: "armcom2", morph_targets: [{ into: "armcom3" }], removed_at: null },
  { unit_name: "armcom3", morph_targets: [], removed_at: null },
  { unit_name: "armsolar", morph_targets: [], removed_at: null },
];

test("every level but the base is kept off the grid", async () => {
  const hidden = await morphedAwayUnits(fakeMorphs(MORPHING), "BA");

  // The stored spelling, because the exclusion is a `unit_name` filter and
  // PostgREST matches it exactly. The base itself stays: it is the cell.
  expect(hidden).toEqual(["armcom2", "armcom3"]);
});

test("a unit that turns into nothing is not hidden by this", async () => {
  const hidden = await morphedAwayUnits(fakeMorphs(MORPHING), "BA");
  expect(hidden).not.toContain("armsolar");
});

test("a retired level does not head the group the grid shows", async () => {
  // The read is live units only, the way the ghost read is. So a patch that
  // retired level one leaves level two heading the cell, rather than the grid
  // losing the commander to a base it is not allowed to draw.
  const retiredBase: Morphing[] = [
    { unit_name: "armcom1", morph_targets: [{ into: "armcom2" }], removed_at: "2026-01-01" },
    { unit_name: "armcom2", morph_targets: [{ into: "armcom3" }], removed_at: null },
    { unit_name: "armcom3", morph_targets: [], removed_at: null },
  ];

  expect(await morphedAwayUnits(fakeMorphs(retiredBase), "BA")).toEqual(["armcom3"]);
});

test("a chain split across the row cap is still one chain", async () => {
  // A game may carry twice what one response holds. A read that took the first
  // page for the whole game would see armcom1 alone, group nothing, and grow
  // back every cell this feature exists to remove.
  const filler: Morphing[] = Array.from({ length: 8 }, (_, index) => ({
    unit_name: `filler${index}`,
    morph_targets: [],
    removed_at: null,
  }));
  const chain: Morphing[] = [
    { unit_name: "armcom1", morph_targets: [{ into: "armcom2" }], removed_at: null },
    { unit_name: "armcom2", morph_targets: [], removed_at: null },
  ];

  expect(await morphedAwayUnits(fakeMorphs([...chain, ...filler], 3), "BA")).toEqual([
    "armcom2",
  ]);
});

/**
 * The release picker on a unit's page (#227). `?v=<release>` has to show what
 * that release said, and the read embeds the revisions to find it.
 *
 * The embed is filtered by the release asked for. Ordering it newest first and
 * taking one, then searching that one for an older release, finds nothing every
 * time and quietly shows current facts under an older release's name.
 */

interface Revision {
  version: string;
  full_name: string | null;
  faction_key: string | null;
  build_options: string[];
  stats: Record<string, unknown>;
}

const REVISIONS: Revision[] = [
  { version: "2.0", full_name: "Commander", faction_key: "arm", build_options: ["armsolar"], stats: { health: 3000 } },
  { version: "1.0", full_name: "Commander Mk I", faction_key: "arm", build_options: [], stats: { health: 2200 } },
];

/**
 * A hub that embeds revisions the way PostgREST does: a filter on an embedded
 * column narrows the embedded rows, and `limit` on a referenced table takes
 * that many per parent row. A fake that ignored either would pass while the
 * picker stayed broken.
 */
function fakeRevisions(revisions: Revision[]): SupabaseClient {
  const unit = (embedded: Revision[]) => {
    const builder = {
      select: () => unit(embedded),
      eq: (column: string, value: unknown) =>
        column === "game_unit_revision.version"
          ? unit(embedded.filter((row) => row.version === value))
          : unit(embedded),
      is: () => unit(embedded),
      contains: () => unit(embedded),
      in: () => unit(embedded),
      order: (_column: string, options?: { referencedTable?: string; ascending?: boolean }) =>
        options?.referencedTable
          ? unit(
              [...embedded].sort((a, b) =>
                options.ascending === false
                  ? b.version.localeCompare(a.version)
                  : a.version.localeCompare(b.version),
              ),
            )
          : unit(embedded),
      limit: (count: number, options?: { referencedTable?: string }) =>
        options?.referencedTable ? unit(embedded.slice(0, count)) : unit(embedded),
      range: () => Promise.resolve({ data: [], error: null }),
      maybeSingle: () =>
        Promise.resolve({
          data: {
            id: 1,
            unit_name: "armcom",
            full_name: "Commander",
            faction_key: "arm",
            build_options: ["armsolar"],
            stats: { health: 3000 },
            snippet: null,
            source_version: "2.0",
            removed_at: null,
            morph_targets: [],
            game_unit_revision: embedded,
          },
          error: null,
        }),
      then: (resolve: (value: unknown) => unknown) =>
        Promise.resolve({ data: [], error: null }).then(resolve),
    };
    return builder;
  };

  const versions = {
    select: () => versions,
    eq: () => versions,
    order: () => Promise.resolve({ data: [{ version: "2.0" }, { version: "1.0" }], error: null }),
  };

  const empty = {
    select: () => empty,
    eq: () => empty,
    then: (resolve: (value: unknown) => unknown) =>
      Promise.resolve({ data: [], error: null }).then(resolve),
  };

  return {
    from: (table: string) =>
      table === "game_version" ? versions : table === "game_unit" ? unit(revisions) : empty,
  } as unknown as SupabaseClient;
}

test("asking for an older release shows what that release said", async () => {
  const page = await loadUnitPage(fakeRevisions(REVISIONS), "BA", "armcom", "1.0");

  expect(page?.shown_version).toBe("1.0");
  expect(page?.full_name).toBe("Commander Mk I");
  expect(page?.stats).toEqual({ health: 2200 });
});

test("a release the hub holds no revision for falls back to current facts", async () => {
  const page = await loadUnitPage(fakeRevisions(REVISIONS), "BA", "armcom", "0.9");

  // An ordinary answer for a unit a later patch added, and the page says so
  // rather than 404ing.
  expect(page?.shown_version).toBeNull();
  expect(page?.full_name).toBe("Commander");
});

test("no release asked for is current facts, and reads no revisions to say so", async () => {
  const page = await loadUnitPage(fakeRevisions(REVISIONS), "BA", "armcom");

  expect(page?.shown_version).toBeNull();
  expect(page?.stats).toEqual({ health: 3000 });
});

/**
 * What a unit's own page says about its stages (#295): the levels in order,
 * what each one costs to reach, what each one unlocks, and the stats running
 * across them so a reader can see what an upgrade buys.
 */

interface StageFixture {
  unit_name: string;
  full_name: string | null;
  build_options: string[];
  stats: Record<string, unknown>;
  morph_targets: unknown;
  removed_at: string | null;
}

function fakeStages(rows: StageFixture[]): SupabaseClient {
  const build = (held: StageFixture[]) => ({
    select: () => build(held),
    eq: () => build(held),
    is: () => build(held),
    order: () => build(held),
    in: (_column: string, names: string[]) =>
      Promise.resolve({
        data: held.filter((row) => names.includes(row.unit_name)),
        error: null,
      }),
    range: (from: number, to: number) =>
      Promise.resolve({ data: held.slice(from, to + 1), error: null }),
  });
  return { from: () => build(rows) } as unknown as SupabaseClient;
}

const COMMANDER: StageFixture[] = [
  {
    unit_name: "armcom1",
    full_name: "Commander",
    build_options: ["armsolar"],
    stats: { health: 3000, buildpower: 100 },
    morph_targets: [{ into: "armcom2", metal: 600, time: 30 }],
    removed_at: null,
  },
  {
    unit_name: "armcom2",
    full_name: "Commander, level 2",
    build_options: ["armsolar", "armvp"],
    stats: { health: 4500, buildpower: 100 },
    morph_targets: [{ into: "armcom3", metal: 1800 }],
    removed_at: null,
  },
  {
    unit_name: "armcom3",
    full_name: "Commander, level 3",
    build_options: ["armsolar", "armvp", "armfus"],
    stats: { health: 6000, buildpower: 200 },
    morph_targets: [],
    removed_at: null,
  },
  {
    unit_name: "armsolar",
    full_name: "Solar Collector",
    build_options: [],
    stats: {},
    morph_targets: [],
    removed_at: null,
  },
  {
    unit_name: "armvp",
    full_name: "Vehicle Plant",
    build_options: [],
    stats: {},
    morph_targets: [],
    removed_at: null,
  },
  {
    unit_name: "armfus",
    full_name: "Fusion Plant",
    build_options: [],
    stats: {},
    morph_targets: [],
    removed_at: null,
  },
];

test("a level's page lists every stage in order, and marks the one being shown", async () => {
  const { stages } = await loadUnitStages(fakeStages(COMMANDER), "BA", "armcom2");

  expect(stages.map((stage) => stage.unit_name)).toEqual(["armcom1", "armcom2", "armcom3"]);
  expect(stages.map((stage) => stage.current)).toEqual([false, true, false]);
  expect(stages.map((stage) => stage.label)).toEqual([
    "Commander",
    "Commander, level 2",
    "Commander, level 3",
  ]);
});

test("a stage says what it unlocks, not everything it can build", async () => {
  const { stages } = await loadUnitStages(fakeStages(COMMANDER), "BA", "armcom1");
  const unlocks = new Map(
    stages.map((stage) => [stage.unit_name, stage.unlocks.map((unit) => unit.name)]),
  );

  // The base has no level before it, so it unlocks nothing rather than
  // claiming everything it builds is new.
  expect(unlocks.get("armcom1")).toEqual([]);
  expect(unlocks.get("armcom2")).toEqual(["armvp"]);
  expect(unlocks.get("armcom3")).toEqual(["armfus"]);
});

test("an unlocked unit is named as the catalog names it", async () => {
  const { stages } = await loadUnitStages(fakeStages(COMMANDER), "BA", "armcom1");
  const second = stages.find((stage) => stage.unit_name === "armcom2");

  expect(second?.unlocks).toEqual([{ name: "armvp", label: "Vehicle Plant" }]);
});

test("how you get to a stage rides along in the game's own words", async () => {
  const { stages } = await loadUnitStages(fakeStages(COMMANDER), "BA", "armcom1");

  expect(stages[0].from).toBeNull();
  expect(stages[0].conditions).toEqual({});
  expect(stages[1].from).toBe("armcom1");
  expect(stages[1].conditions).toEqual({ metal: 600, time: 30 });
  expect(stages[2].conditions).toEqual({ metal: 1800 });
});

test("the stats run across the stages, with the rows that move marked", async () => {
  const { stage_stats } = await loadUnitStages(fakeStages(COMMANDER), "BA", "armcom1");
  const rows = new Map(stage_stats.map((row) => [row.key, row]));

  expect(rows.get("health")?.values).toEqual(["3000", "4500", "6000"]);
  expect(rows.get("health")?.changed).toBe(true);
  // Build power is the same at levels one and two and different at three, so
  // the row still moves. A row that never moves is not worth a reader's eye.
  expect(rows.get("buildpower")?.values).toEqual(["100", "100", "200"]);
  expect(rows.get("buildpower")?.changed).toBe(true);
});

test("a stat that holds still across every stage is not marked as moving", async () => {
  const flat: StageFixture[] = [
    { ...COMMANDER[0], stats: { health: 3000 } },
    { ...COMMANDER[1], stats: { health: 3000 } },
    { ...COMMANDER[2], stats: { health: 3000 } },
    ...COMMANDER.slice(3),
  ];
  const { stage_stats } = await loadUnitStages(fakeStages(flat), "BA", "armcom1");

  expect(stage_stats.find((row) => row.key === "health")?.changed).toBe(false);
});

test("a unit that turns into nothing gets no strip at all", async () => {
  const { stages, stage_stats } = await loadUnitStages(fakeStages(COMMANDER), "BA", "armsolar");

  expect(stages).toEqual([]);
  expect(stage_stats).toEqual([]);
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

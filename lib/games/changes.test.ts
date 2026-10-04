import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import {
  diffReleases,
  groupByFaction,
  loadReleaseChanges,
  resolveReleasePair,
  type ReleaseUnit,
} from "./changes";

function unit(name: string, over: Partial<ReleaseUnit> = {}): ReleaseUnit {
  return {
    unit_name: name,
    full_name: name.toUpperCase(),
    faction_key: "arm",
    build_options: [],
    stats: { health: 100 },
    ...over,
  };
}

test("a unit only the newer release holds is added", () => {
  const changes = diffReleases([unit("a")], [unit("a"), unit("b")]);
  expect(changes.added.map((u) => u.unit_name)).toEqual(["b"]);
  expect(changes.removed).toEqual([]);
  expect(changes.changed).toEqual([]);
  expect(changes.unchanged).toBe(1);
});

test("a unit only the older release holds is removed", () => {
  const changes = diffReleases([unit("a"), unit("b")], [unit("a")]);
  expect(changes.removed.map((u) => u.unit_name)).toEqual(["b"]);
  expect(changes.added).toEqual([]);
});

test("a unit neither release holds is nowhere in the answer", () => {
  // A revision is the only evidence a release held a unit, and `removed_at` is
  // today's state and not a release's. Retired later or not, a unit with no
  // revision in either release did not change between them.
  const changes = diffReleases([unit("a")], [unit("a")]);
  expect(changes.added).toEqual([]);
  expect(changes.removed).toEqual([]);
  expect(changes.unchanged).toBe(1);
});

test("a unit with the same facts in both releases is unchanged", () => {
  const changes = diffReleases([unit("a")], [unit("a")]);
  expect(changes.changed).toEqual([]);
  expect(changes.unchanged).toBe(1);
});

test("a changed stat is listed with both values and its label", () => {
  const changes = diffReleases(
    [unit("a", { stats: { health: 100, metalCost: 50 } })],
    [unit("a", { stats: { health: 120, metalCost: 50 } })],
  );
  expect(changes.changed).toHaveLength(1);
  expect(changes.changed[0].stats).toEqual([{ key: "health", label: "Health", from: "100", to: "120" }]);
});

test("a stat only one release carries reads as a dash on the other side", () => {
  const changes = diffReleases(
    [unit("a", { stats: { health: 100 } })],
    [unit("a", { stats: { health: 100, buildTime: 30 } })],
  );
  expect(changes.changed[0].stats).toEqual([{ key: "buildTime", label: "Build time", from: "-", to: "30" }]);

  const dropped = diffReleases(
    [unit("a", { stats: { health: 100, buildTime: 30 } })],
    [unit("a", { stats: { health: 100 } })],
  );
  expect(dropped.changed[0].stats).toEqual([{ key: "buildTime", label: "Build time", from: "30", to: "-" }]);
});

test("a stat that is null on one side and absent on the other is not a change", () => {
  const changes = diffReleases([unit("a", { stats: { health: 1, x: null } })], [unit("a", { stats: { health: 1 } })]);
  expect(changes.changed).toEqual([]);
});

test("a number and the same number as a string read the same, as the compare page reads them", () => {
  const changes = diffReleases([unit("a", { stats: { health: 100 } })], [unit("a", { stats: { health: "100" } })]);
  expect(changes.changed).toEqual([]);
});

test("a number and a different string is a change", () => {
  const changes = diffReleases([unit("a", { stats: { health: 100 } })], [unit("a", { stats: { health: "high" } })]);
  expect(changes.changed[0].stats).toEqual([{ key: "health", label: "Health", from: "100", to: "high" }]);
});

test("nested and array stats differ by their printed value", () => {
  const weapons = (damage: number) => [{ range: 300, damage }];
  const same = diffReleases([unit("a", { stats: { weapons: weapons(10) } })], [unit("a", { stats: { weapons: weapons(10) } })]);
  expect(same.changed).toEqual([]);

  const changes = diffReleases([unit("a", { stats: { weapons: weapons(10) } })], [unit("a", { stats: { weapons: weapons(12) } })]);
  expect(changes.changed[0].stats).toEqual([
    { key: "weapons", label: "weapons", from: '[{"range":300,"damage":10}]', to: '[{"range":300,"damage":12}]' },
  ]);
});

test("stats read in the compare page's order, known keys first", () => {
  const changes = diffReleases(
    [unit("a", { stats: { zeta: 1, metalCost: 1, health: 1 } })],
    [unit("a", { stats: { zeta: 2, metalCost: 2, health: 2 } })],
  );
  expect(changes.changed[0].stats.map((s) => s.key)).toEqual(["health", "metalCost", "zeta"]);
});

test("build options gained and lost are listed, lower cased and sorted", () => {
  const changes = diffReleases(
    [unit("a", { build_options: ["Armmex", "armsolar"] })],
    [unit("a", { build_options: ["armsolar", "armwin", "ARMLAB"] })],
  );
  expect(changes.changed[0].builds).toEqual({ gained: ["armlab", "armwin"], lost: ["armmex"] });
});

test("build options in another order are not a change", () => {
  // The unit page and the tree sort build options, so the array order carries
  // no meaning.
  const changes = diffReleases(
    [unit("a", { build_options: ["x", "y"] })],
    [unit("a", { build_options: ["y", "x", "x"] })],
  );
  expect(changes.changed).toEqual([]);
});

test("a rename on its own is a change", () => {
  const changes = diffReleases([unit("a", { full_name: "Old" })], [unit("a", { full_name: "New" })]);
  expect(changes.changed).toHaveLength(1);
  expect(changes.changed[0].renamed).toEqual({ from: "Old", to: "New" });
  expect(changes.changed[0].full_name).toBe("New");
  expect(changes.changed[0].stats).toEqual([]);
});

test("a unit moving to another faction is a change", () => {
  const changes = diffReleases([unit("a", { faction_key: "arm" })], [unit("a", { faction_key: "cor" })]);
  expect(changes.changed[0].faction).toEqual({ from: "arm", to: "cor" });
  expect(changes.changed[0].faction_key).toBe("cor");
});

test("the same release on both sides changes nothing", () => {
  const units = [unit("a"), unit("b")];
  const changes = diffReleases(units, units);
  expect(changes).toEqual({ added: [], removed: [], changed: [], unchanged: 2 });
});

test("a unit held by an intermediate release only is nowhere in a pair that skips it", () => {
  // Release 2 held `mid` and releases 1 and 3 did not. Comparing 1 with 3 must
  // not invent it: only revisions in the two releases asked for count.
  const changes = diffReleases([unit("a")], [unit("a")]);
  expect(changes.added.map((u) => u.unit_name)).not.toContain("mid");
});

test("an added unit keeps its name and faction, a removed one its older ones", () => {
  const changes = diffReleases(
    [unit("gone", { full_name: "Gone", faction_key: "cor" })],
    [unit("new", { full_name: "New", faction_key: "arm" })],
  );
  expect(changes.added).toEqual([{ unit_name: "new", full_name: "New", faction_key: "arm" }]);
  expect(changes.removed).toEqual([{ unit_name: "gone", full_name: "Gone", faction_key: "cor" }]);
});

test("groupByFaction orders by the game's sides, then the rest, and sorts within a group", () => {
  const groups = groupByFaction(
    [
      { unit_name: "c", full_name: "Zed", faction_key: "cor" },
      { unit_name: "a", full_name: "Beta", faction_key: "arm" },
      { unit_name: "b", full_name: "Alpha", faction_key: "arm" },
      { unit_name: "d", full_name: null, faction_key: null },
      { unit_name: "e", full_name: "Odd", faction_key: "gone" },
    ],
    [
      { key: "arm", name: "Arm" },
      { key: "cor", name: "Cor" },
    ],
  );
  expect(groups.map((g) => [g.key, g.name, g.units.map((u) => u.unit_name)])).toEqual([
    ["arm", "Arm", ["b", "a"]],
    ["cor", "Cor", ["c"]],
    [null, "No faction", ["d", "e"]],
  ]);
});

test("resolveReleasePair defaults to the two newest releases, older first", () => {
  expect(resolveReleasePair(["3", "2", "1"], undefined, undefined)).toEqual({
    from: "2",
    to: "3",
    notes: [],
  });
});

test("resolveReleasePair keeps what was asked for, including a reversed pair", () => {
  expect(resolveReleasePair(["3", "2", "1"], "3", "1")).toEqual({ from: "3", to: "1", notes: [] });
});

test("resolveReleasePair falls back and says so for a release it does not hold", () => {
  const pair = resolveReleasePair(["3", "2", "1"], "nope", "1");
  expect(pair.from).toBe("2");
  expect(pair.to).toBe("1");
  expect(pair.notes).toEqual(['The hub holds no release called "nope".']);
});

test("resolveReleasePair does not default one side onto the other", () => {
  expect(resolveReleasePair(["3", "2", "1"], "nope", "2")).toMatchObject({ from: "1", to: "2" });
  expect(resolveReleasePair(["3", "2", "1"], "2", "nope")).toMatchObject({ from: "2", to: "3" });
  expect(resolveReleasePair(["3", "2", "1"], "3", undefined)).toMatchObject({ from: "3", to: "2" });
});

test("resolveReleasePair has no pair for fewer than two releases", () => {
  expect(resolveReleasePair(["1"], undefined, undefined)).toEqual({ from: null, to: null, notes: [] });
  expect(resolveReleasePair([], "1", "2")).toEqual({ from: null, to: null, notes: [] });
});

/** A client whose revisions come back a window at a time, counting the reads. */
function fakeRevisions(rows: unknown[]) {
  const calls: { table: string; filters: [string, unknown][] }[] = [];
  const client = {
    from(table: string) {
      const call = { table, filters: [] as [string, unknown][] };
      calls.push(call);
      const builder = {
        select: () => builder,
        eq: (column: string, value: unknown) => (call.filters.push([column, value]), builder),
        in: (column: string, value: unknown) => (call.filters.push([column, value]), builder),
        order: () => builder,
        range: (from: number, to: number) =>
          Promise.resolve({ data: rows.slice(from, to + 1), error: null }),
      };
      return builder;
    },
  };
  return { client: client as unknown as SupabaseClient, calls };
}

function revision(name: string, version: string, over: Record<string, unknown> = {}) {
  return {
    version,
    full_name: name.toUpperCase(),
    faction_key: "arm",
    build_options: [],
    stats: { health: 1 },
    game_unit: { unit_name: name },
    ...over,
  };
}

test("loadReleaseChanges reads both releases with one paged query and splits the rows by release", async () => {
  const { client, calls } = fakeRevisions([
    revision("kept", "1"),
    revision("kept", "2", { stats: { health: 2 } }),
    revision("gone", "1"),
    revision("fresh", "2"),
  ]);
  const changes = await loadReleaseChanges(client, "game", "1", "2");
  // One window of rows and the empty window `readAll` stops on, never a read per unit.
  expect(calls).toHaveLength(2);
  expect(calls.every((call) => call.table === "game_unit_revision")).toBe(true);
  expect(changes?.added.map((u) => u.unit_name)).toEqual(["fresh"]);
  expect(changes?.removed.map((u) => u.unit_name)).toEqual(["gone"]);
  expect(changes?.changed.map((u) => u.unit_name)).toEqual(["kept"]);
});

test("loadReleaseChanges is null when the read fails", async () => {
  const failing = {
    from: () => {
      const builder = {
        select: () => builder,
        eq: () => builder,
        in: () => builder,
        order: () => builder,
        range: () => Promise.resolve({ data: null, error: { message: "boom" } }),
      };
      return builder;
    },
  } as unknown as SupabaseClient;
  expect(await loadReleaseChanges(failing, "game", "1", "2")).toBeNull();
});

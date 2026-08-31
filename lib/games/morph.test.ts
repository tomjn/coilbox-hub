import { expect, test } from "bun:test";
import { baseIndex, morphGroups } from "./morph";

/**
 * The grouping a morph chain gets (#295): five rows for a five level commander
 * are one unit at five stages of its life, and every reader has to agree which
 * row is the one a grid cell shows.
 *
 * The graph is not a chain. It branches, it converges, and a game can define a
 * cycle, so every rule below has to hold on a mess rather than on a tidy line
 * of levels.
 */

/** A unit row as the readers hand it over: whatever the catalog stored. */
function unit(name: string, ...into: string[]) {
  return { unit_name: name, morph_targets: into.map((target) => ({ into: target })) };
}

test("a chain is one group, based on the unit nothing morphs into", () => {
  const groups = morphGroups([
    unit("armcom1", "armcom2"),
    unit("armcom2", "armcom3"),
    unit("armcom3"),
  ]);

  expect(groups).toHaveLength(1);
  expect(groups[0].base).toBe("armcom1");
  expect(groups[0].stages.map((stage) => stage.name)).toEqual([
    "armcom1",
    "armcom2",
    "armcom3",
  ]);
});

test("each stage says which stage it is reached from, and the base says none", () => {
  const groups = morphGroups([
    unit("armcom1", "armcom2"),
    unit("armcom2", "armcom3"),
    unit("armcom3"),
  ]);

  expect(groups[0].stages.map((stage) => stage.from)).toEqual([
    null,
    "armcom1",
    "armcom2",
  ]);
});

test("a stage that morphs into two things lists both, alphabetically", () => {
  const groups = morphGroups([
    unit("armcom", "zeta", "alpha"),
    unit("alpha"),
    unit("zeta"),
  ]);

  expect(groups[0].stages.map((stage) => stage.name)).toEqual([
    "armcom",
    "alpha",
    "zeta",
  ]);
  expect(groups[0].stages[0].into.map((edge) => edge.into)).toEqual(["alpha", "zeta"]);
});

test("a cycle has no unit nothing morphs into, so the first by code unit heads it", () => {
  const groups = morphGroups([unit("beta", "alpha"), unit("alpha", "beta")]);

  expect(groups).toHaveLength(1);
  expect(groups[0].base).toBe("alpha");
  // Every member once. A walk that revisits would be the million drawings #257
  // cost the build tree.
  expect(groups[0].stages.map((stage) => stage.name)).toEqual(["alpha", "beta"]);
});

test("two units morphing into one thing are one group, headed by the first root", () => {
  const groups = morphGroups([
    unit("corvp", "shared"),
    unit("armvp", "shared"),
    unit("shared"),
  ]);

  expect(groups).toHaveLength(1);
  expect(groups[0].base).toBe("armvp");
  // corvp is not reachable from armvp, so it lands after what is, and it is
  // reached from nothing rather than claiming a predecessor it never had.
  expect(groups[0].stages.map((stage) => stage.name)).toEqual([
    "armvp",
    "shared",
    "corvp",
  ]);
  expect(groups[0].stages[2].from).toBeNull();
});

test("an edge to a unit nobody holds is dropped rather than grouping a ghost", () => {
  const groups = morphGroups([unit("armcom1", "armcom2", "typo"), unit("armcom2")]);

  expect(groups[0].stages.map((stage) => stage.name)).toEqual(["armcom1", "armcom2"]);
  expect(groups[0].stages[0].into.map((edge) => edge.into)).toEqual(["armcom2"]);
});

test("a unit that turns into nothing and nothing turns into is not a group", () => {
  expect(morphGroups([unit("armsolar"), unit("armmex")])).toEqual([]);
});

test("keys match case-insensitively, and the stored spelling rides along", () => {
  const groups = morphGroups([
    { unit_name: "ArmCom1", morph_targets: [{ into: "ARMCOM2" }] },
    { unit_name: "armcom2", morph_targets: [] },
  ]);

  expect(groups[0].base).toBe("armcom1");
  // The URL segment and the grid's exclusion filter both need what was stored.
  expect(groups[0].stages.map((stage) => stage.unit_name)).toEqual(["ArmCom1", "armcom2"]);
});

test("the game's own words for what a morph costs ride the edge", () => {
  const groups = morphGroups([
    {
      unit_name: "armcom1",
      morph_targets: [{ into: "armcom2", metal: 600, time: 30, tech: "Level 2" }],
    },
    { unit_name: "armcom2", morph_targets: [] },
  ]);

  expect(groups[0].stages[0].into[0].conditions).toEqual({
    metal: 600,
    time: 30,
    tech: "Level 2",
  });
  // The stage reached carries the same conditions, so a page drawing the
  // stages in order does not have to walk back up the edges to say what each
  // one costs.
  expect(groups[0].stages[1].conditions).toEqual({
    metal: 600,
    time: 30,
    tech: "Level 2",
  });
});

test("morph targets that are not a list of objects naming a unit are ignored", () => {
  // The parser refuses these on the way in, so anything here arrived before it
  // did or by hand. A reader drops what it cannot read rather than throwing.
  const groups = morphGroups([
    { unit_name: "a", morph_targets: "nonsense" },
    { unit_name: "b", morph_targets: [null, { into: 7 }, {}, { into: "c" }] },
    { unit_name: "c", morph_targets: null },
  ]);

  expect(groups.map((group) => group.base)).toEqual(["b"]);
  expect(groups[0].stages.map((stage) => stage.name)).toEqual(["b", "c"]);
});

test("two separate chains are two groups, in base order", () => {
  const groups = morphGroups([
    unit("corcom1", "corcom2"),
    unit("corcom2"),
    unit("armcom1", "armcom2"),
    unit("armcom2"),
  ]);

  expect(groups.map((group) => group.base)).toEqual(["armcom1", "corcom1"]);
});

test("the base index answers for every member, including the base", () => {
  const groups = morphGroups([
    unit("armcom1", "armcom2"),
    unit("armcom2", "armcom3"),
    unit("armcom3"),
    unit("armsolar"),
  ]);

  const bases = baseIndex(groups);
  expect(bases.get("armcom1")).toBe("armcom1");
  expect(bases.get("armcom3")).toBe("armcom1");
  // A unit in no group is absent, so a caller can tell "no group" from "its
  // own base" without a second lookup.
  expect(bases.has("armsolar")).toBe(false);
});

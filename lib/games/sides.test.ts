import { expect, test } from "bun:test";
import { UNIT_BUILDPIC_VARIANT } from "@/lib/assets/asset";
import { identityKey } from "@/lib/assets/have";
import type { HeldRow } from "@/lib/assets/resolve";
import { assembleSides, gamesNeedingSample } from "./sides";

/**
 * Which start unit a side is drawn with, against rows shaped like the reads.
 * The decisions under test: Random is not a side, a start unit the catalog
 * files under no side is not drawn, a start unit with no buildpic held is drawn
 * with a placeholder rather than dropped, and a game that has named no start
 * unit at all falls back to a random sample of its own units.
 */

function held(game: string, unitName: string): [string, HeldRow] {
  return [
    identityKey({ keyedOn: "unit", game, unitName, variant: UNIT_BUILDPIC_VARIANT }),
    {
      tier: "static",
      path: `units/${game}/${unitName}.webp`,
      width: 64,
      height: 64,
      moderation: "approved",
      world_height_min: null,
      world_height_max: null,
    },
  ];
}

const BYAR = {
  shortname: "BYAR",
  start_units: ["armcom", "corcom", "dummycom", "legcom"],
  game_faction: [
    { key: "legion", name: "Legion" },
    { key: "random", name: "Random" },
    { key: "armada", name: "Armada" },
    { key: "cortex", name: "Cortex" },
  ],
};

const UNITS = [
  { unit_name: "armcom", full_name: "Armada Commander", faction_key: "armada", game: { shortname: "BYAR" } },
  { unit_name: "corcom", full_name: null, faction_key: "cortex", game: { shortname: "BYAR" } },
  { unit_name: "dummycom", full_name: "Random", faction_key: "random", game: { shortname: "BYAR" } },
];

test("sides are alphabetical with Random left out", () => {
  const sides = assembleSides([BYAR], UNITS, new Map([held("BYAR", "armcom")]));
  expect(sides.get("BYAR")?.factions.map((faction) => faction.name)).toEqual([
    "Armada",
    "Cortex",
    "Legion",
  ]);
});

test("a side is drawn with its start unit only when the catalog places it", () => {
  const sides = assembleSides(
    [BYAR],
    UNITS,
    new Map([held("BYAR", "armcom"), held("BYAR", "dummycom")]),
  );
  const commanders = sides.get("BYAR")?.commanders;
  expect(commanders?.get("armada")?.label).toBe("Armada Commander");
  // legcom is not in the catalog at all, and dummycom belongs to the side that
  // is not one.
  expect(commanders?.has("legion")).toBe(false);
  expect(commanders?.has("random")).toBe(false);
});

/** Whether a picture has been uploaded is not a fact about the unit, so the
 *  side keeps its start unit either way and the ladder answers with the
 *  placeholder. */
test("a start unit with no buildpic held is drawn as a placeholder", () => {
  const sides = assembleSides([BYAR], UNITS, new Map([held("BYAR", "armcom")]));
  const commanders = sides.get("BYAR")?.commanders;
  expect(commanders?.get("armada")?.picture.from).toBe("static");
  expect(commanders?.get("cortex")?.picture.from).toBe("placeholder");
  // No full_name reported, so the def name is the label.
  expect(commanders?.get("cortex")?.label).toBe("corcom");
});

test("a unit is only matched within its own game", () => {
  const other = { ...BYAR, shortname: "XTA", start_units: ["armcom"] };
  const sides = assembleSides([other], UNITS, new Map([held("XTA", "armcom")]));
  expect(sides.get("XTA")?.commanders.size).toBe(0);
});

test("a game with no start units still lists its sides", () => {
  const sides = assembleSides([{ ...BYAR, start_units: null }], [], new Map());
  expect(sides.get("BYAR")?.factions).toHaveLength(3);
  expect(sides.get("BYAR")?.commanders.size).toBe(0);
});

test("a game gets a sample when nothing it names lands on one of its sides", () => {
  expect(gamesNeedingSample([{ ...BYAR, start_units: null }], [])).toEqual(["BYAR"]);
  expect(gamesNeedingSample([{ ...BYAR, start_units: [] }], [])).toEqual(["BYAR"]);
  // Named, and every one of them unplaceable: legcom is unknown and dummycom is
  // filed under Random, which is not a side.
  expect(gamesNeedingSample([{ ...BYAR, start_units: ["legcom", "dummycom"] }], UNITS)).toEqual([
    "BYAR",
  ]);
  expect(gamesNeedingSample([BYAR], UNITS)).toEqual([]);
});

test("a sampled unit is drawn like a start unit, and only when there are none", () => {
  const sample = [
    { unit_name: "armflash", full_name: "Flash" },
    { unit_name: "corraid", full_name: null },
  ];
  const sampled = new Map([["BYAR", sample]]);

  const none = assembleSides([{ ...BYAR, start_units: null }], [], new Map(), sampled);
  expect(none.get("BYAR")?.sample.map((unit) => unit.label)).toEqual(["Flash", "corraid"]);
  expect(none.get("BYAR")?.sample[0]?.picture.from).toBe("placeholder");

  const some = assembleSides([BYAR], UNITS, new Map([held("BYAR", "armcom")]), sampled);
  expect(some.get("BYAR")?.sample).toEqual([]);
});

import { expect, test } from "bun:test";
import { UNIT_BUILDPIC_VARIANT } from "@/lib/assets/asset";
import { identityKey } from "@/lib/assets/have";
import type { HeldRow } from "@/lib/assets/resolve";
import { assembleSides } from "./sides";

/**
 * Which start unit a side is drawn with, against rows shaped like the reads.
 * The decisions under test: Random is not a side, a start unit the catalog
 * files under no side is not drawn, and a start unit with no buildpic held is
 * not drawn either.
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

test("a side is drawn with its start unit only when the catalog places it and a buildpic is held", () => {
  const sides = assembleSides(
    [BYAR],
    UNITS,
    new Map([held("BYAR", "armcom"), held("BYAR", "dummycom")]),
  );
  const commanders = sides.get("BYAR")?.commanders;
  expect(commanders?.get("armada")?.label).toBe("Armada Commander");
  // corcom is placed but has no picture, legcom is not in the catalog at all,
  // and dummycom belongs to the side that is not one.
  expect(commanders?.has("cortex")).toBe(false);
  expect(commanders?.has("legion")).toBe(false);
  expect(commanders?.has("random")).toBe(false);
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

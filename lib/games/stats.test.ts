import { expect, test } from "bun:test";
import {
  compareStatRows,
  formatStatValue,
  statLabel,
  statRows,
  tabularColumns,
  tabularStatRows,
} from "./stats";

/**
 * How a stats blob becomes a table (#227). The blob is schemaless by design, so
 * what is under test is the honesty rules: known keys lead in reading order,
 * unknown keys print as themselves rather than being hidden, and absent stays
 * absent instead of becoming a zero that claims something.
 */

test("known keys lead in reading order, unknown keys follow alphabetically", () => {
  const rows = statRows({
    zeta: 1,
    energyCost: 800,
    health: 5000,
    metalCost: 200,
    alpha: 2,
  });
  expect(rows.map((row) => row.key)).toEqual([
    "health",
    "metalCost",
    "energyCost",
    "alpha",
    "zeta",
  ]);
});

test("an unknown key prints as itself", () => {
  expect(statLabel("shieldCapacity")).toBe("shieldCapacity");
  expect(statLabel("health")).toBe("Health");
});

test("absent stays absent, and structures arrive as data", () => {
  expect(formatStatValue(null)).toBe("-");
  expect(formatStatValue(undefined)).toBe("-");
  expect(formatStatValue(4500)).toBe("4500");
  expect(formatStatValue(true)).toBe("true");
  expect(formatStatValue("yes")).toBe("yes");
  expect(formatStatValue({ damage: 40, reload: 1.5 })).toBe('{"damage":40,"reload":1.5}');
});

/**
 * A weapons summary is an array of records, and JSON of that shape is what
 * readers saw instead of a table (#261).
 */

const WEAPONS = [
  { range: 300, damage: 75, reload: 0.4, projectile: "BeamLaser" },
  { range: 250, damage: 99999, projectile: "DGun" },
];

test("an array of flat records is tabular", () => {
  expect(tabularStatRows(WEAPONS)).toEqual(WEAPONS);
});

test("anything else keeps its compact JSON", () => {
  expect(tabularStatRows(300)).toBeNull();
  expect(tabularStatRows(["BeamLaser", "DGun"])).toBeNull();
  expect(tabularStatRows([])).toBeNull();
  expect(tabularStatRows([{ ok: true }, "not a record"])).toBeNull();
});

test("columns come back in first-appearance order, and every column survives", () => {
  // DGun carries no reload; the column stays because the BeamLaser does.
  expect(tabularColumns(WEAPONS)).toEqual(["range", "damage", "reload", "projectile"]);
});

test("compare rows are the union of both sides, known keys first then alphabetical", () => {
  const rows = compareStatRows(
    { zeta: 1, health: 10, alpha: 2 },
    { energyCost: 5, health: 10, beta: 3 },
  );
  expect(rows.map((row) => row.key)).toEqual(["health", "energyCost", "alpha", "beta", "zeta"]);
  expect(rows.find((row) => row.key === "energyCost")?.label).toBe("Energy cost");
});

test("compare rows mark a differing value and leave an equal one", () => {
  const rows = compareStatRows({ health: 10, metalCost: 50 }, { health: 12, metalCost: 50 });
  expect(rows.map((row) => [row.key, row.changed])).toEqual([
    ["health", true],
    ["metalCost", false],
  ]);
  expect(rows[0]).toMatchObject({ left: "10", right: "12" });
});

test("a stat on one side only reads as a dash on the other and counts as different", () => {
  const rows = compareStatRows({ health: 10 }, { health: 10, buildSpeed: 80 });
  const only = rows.find((row) => row.key === "buildSpeed");
  expect(only).toMatchObject({ left: "-", right: "80", changed: true });
});

test("array and object values compare by what they print", () => {
  const same = compareStatRows({ weapons: [{ range: 1 }] }, { weapons: [{ range: 1 }] });
  expect(same[0].changed).toBe(false);
  const different = compareStatRows({ weapons: [{ range: 1 }] }, { weapons: [{ range: 2 }] });
  expect(different[0].changed).toBe(true);
  expect(different[0].right).toBe('[{"range":2}]');
});

test("a number against the same digits as a string is not marked different", () => {
  const rows = compareStatRows({ health: 10 }, { health: "10" });
  expect(rows[0].changed).toBe(false);
  const other = compareStatRows({ health: 10 }, { health: "ten" });
  expect(other[0].changed).toBe(true);
});

test("two empty sides give no rows", () => {
  expect(compareStatRows({}, {})).toEqual([]);
});

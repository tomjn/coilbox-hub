import { expect, test } from "bun:test";
import { formatStatValue, statLabel, statRows } from "./stats";

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

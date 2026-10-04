import { expect, test } from "bun:test";
import { distinct, facetsFromRows } from "@/lib/gallery/cached";

test("a facet offers each value once, sorted", () => {
  expect(distinct(["bar", "arm", "bar"])).toEqual(["arm", "bar"]);
});

test("a row with nothing in the column offers no chip", () => {
  expect(distinct(["bar", null, undefined as unknown as string])).toEqual(["bar"]);
  expect(distinct(undefined)).toEqual([]);
});

test("a facet stays a row of chips rather than a list", () => {
  const many = Array.from({ length: 40 }, (_, i) => `game-${String(i).padStart(2, "0")}`);
  expect(distinct(many)).toHaveLength(20);
});

test("the chips are split by facet, sorted, with empty values left out", () => {
  expect(
    facetsFromRows([
      { facet: "map", value: "Seed Map" },
      { facet: "game", value: "tabtest" },
      { facet: "game", value: "bar" },
      { facet: "game", value: null },
      { facet: "map", value: "" },
    ]),
  ).toEqual({ games: ["bar", "tabtest"], maps: ["Seed Map"] });
  expect(facetsFromRows(null)).toEqual({ games: [], maps: [] });
});

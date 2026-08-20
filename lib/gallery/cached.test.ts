import { expect, test } from "bun:test";
import { distinct } from "@/lib/gallery/cached";

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

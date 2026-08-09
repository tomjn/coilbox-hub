import { expect, test } from "bun:test";
import { filterHref, parseFilters } from "./query";

test("filters come out of the query string", () => {
  const filters = parseFilters({
    kind: "preset",
    game: "Beyond All Reason",
    tag: "ECO",
    page: "3",
  });

  expect(filters.kind).toBe("preset");
  expect(filters.game).toBe("Beyond All Reason");
  expect(filters.tag).toBe("eco");
  expect(filters.page).toBe(3);
});

test("an unrecognised kind is dropped rather than passed to the database", () => {
  expect(parseFilters({ kind: "campaign" }).kind).toBeNull();
  expect(parseFilters({ kind: "'; drop table item; --" }).kind).toBeNull();
});

test("a nonsense page falls back to the first", () => {
  expect(parseFilters({ page: "0" }).page).toBe(1);
  expect(parseFilters({ page: "-4" }).page).toBe(1);
  expect(parseFilters({ page: "banana" }).page).toBe(1);
});

test("empty values are treated as absent", () => {
  const filters = parseFilters({ game: "   ", map: "" });
  expect(filters.game).toBeNull();
  expect(filters.map).toBeNull();
});

test("a repeated parameter takes the first, not an array", () => {
  expect(parseFilters({ game: ["One", "Two"] }).game).toBe("One");
});

test("changing a filter keeps the others and drops the page", () => {
  const current = parseFilters({ kind: "preset", game: "BAR", page: "5" });

  expect(filterHref(current, { map: "Comet" })).toBe(
    "/gallery?kind=preset&game=BAR&map=Comet",
  );
});

test("paging keeps every filter", () => {
  const current = parseFilters({ kind: "scenario", tag: "hard" });

  expect(filterHref(current, { page: 2 })).toBe(
    "/gallery?kind=scenario&tag=hard&page=2",
  );
});

test("clearing everything is a bare path", () => {
  const current = parseFilters({ kind: "preset", game: "BAR" });

  expect(filterHref(current, { kind: null, game: null })).toBe("/gallery");
});

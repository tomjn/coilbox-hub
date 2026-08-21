import { expect, test } from "bun:test";
import { gameUnitIsRetired, parseGameLinks } from "./catalog";

/**
 * The two decisions `lib/games/catalog.ts` makes on every reader's behalf:
 * what counts as a usable link, and what counts as retired (#223). The row
 * shapes follow the migration and are held there, so these cover the helpers
 * rather than restate the schema.
 */

test("links that are not an array of labelled rows contribute nothing", () => {
  expect(parseGameLinks("[]")).toEqual([]);
  expect(parseGameLinks({ label: "forum" })).toEqual([]);
  expect(parseGameLinks(null)).toEqual([]);
  expect(parseGameLinks([null, "forum", 42])).toEqual([]);
});

test("a link needs both a non-empty label and a non-empty url", () => {
  expect(
    parseGameLinks([
      { label: "Forum", url: "https://example.test" },
      { label: "", url: "https://example.test" },
      { label: "Wiki", url: "" },
      { label: "Code", url: "   " },
    ]),
  ).toEqual([{ label: "Forum", url: "https://example.test" }]);
});

test("one malformed entry costs itself and not the rest", () => {
  expect(
    parseGameLinks([{ label: "Forum", url: "https://example.test" }, { label: "Wiki" }]),
  ).toEqual([{ label: "Forum", url: "https://example.test" }]);
});

test("retirement is the presence of a stamp, not a flag", () => {
  expect(gameUnitIsRetired({ removed_at: null })).toBe(false);
  expect(gameUnitIsRetired({ removed_at: "2026-08-21T00:00:00Z" })).toBe(true);
});

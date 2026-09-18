import { expect, test } from "bun:test";
import { gameUnitIsRetired, parseConquestFactions, parseGameLinks } from "./catalog";

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

test("conquest factions that are not an array of named entries contribute nothing", () => {
  expect(parseConquestFactions("[]")).toEqual([]);
  expect(parseConquestFactions({ name: "Arm" })).toEqual([]);
  expect(parseConquestFactions(null)).toEqual([]);
  expect(parseConquestFactions([null, "Arm", 42])).toEqual([]);
  expect(parseConquestFactions([{ color: "#2f7dff" }])).toEqual([]);
  expect(parseConquestFactions([{ name: "  " }])).toEqual([]);
});

test("a colour and a side are kept only when they are usable, and a name alone still counts", () => {
  expect(
    parseConquestFactions([
      { name: "Arm", color: "#2f7dff", side: "ARM" },
      { name: "Core", color: "not-a-colour", side: "  " },
      { name: "  Cortex  " },
    ]),
  ).toEqual([
    { name: "Arm", color: "#2f7dff", side: "ARM" },
    { name: "Core" },
    { name: "Cortex" },
  ]);
});

test("two factions may name the same in-game side", () => {
  expect(
    parseConquestFactions([
      { name: "House Arm-1", side: "ARM" },
      { name: "House Arm-2", side: "ARM" },
    ]),
  ).toEqual([
    { name: "House Arm-1", side: "ARM" },
    { name: "House Arm-2", side: "ARM" },
  ]);
});

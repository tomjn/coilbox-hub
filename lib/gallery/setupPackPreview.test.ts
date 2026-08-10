import { expect, test } from "bun:test";
import { setupPackGameNames } from "./setupPackPreview";

test("a pack published as a collection names every game it carries", () => {
  expect(
    setupPackGameNames({
      games: [{ name: "Beyond All Reason 1.2" }, { name: "SplinterFaction 0.1.78" }],
    }),
  ).toEqual(["Beyond All Reason 1.2", "SplinterFaction 0.1.78"]);
});

test("a pack published before the change still reads its single game", () => {
  expect(setupPackGameNames({ game: { name: "Beyond All Reason 1.2" } })).toEqual([
    "Beyond All Reason 1.2",
  ]);
});

test("a pack naming no game at all reads as none", () => {
  expect(setupPackGameNames({ maps: ["Comet Catcher Remake"] })).toEqual([]);
  expect(setupPackGameNames({ games: [] })).toEqual([]);
});

test("an entry with no archive name is dropped rather than shown blank", () => {
  // `name` is optional on a GameIdentity: an item that pins no build carries
  // only a shortname. There is nothing to print for it in a list of builds.
  expect(
    setupPackGameNames({ games: [{ shortname: "BAR" }, { name: "SplinterFaction 0.1.78" }] }),
  ).toEqual(["SplinterFaction 0.1.78"]);
});

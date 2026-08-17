import { expect, test } from "bun:test";
import {
  setupPackEngine,
  setupPackGameNames,
  setupPackMapNames,
  setupPackMaps,
} from "./setupPackPreview";

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

test("a pack lists its maps in the order it installs them", () => {
  expect(
    setupPackMapNames({ maps: ["All That Smolders v1.2", "All That Glitters v2.2.3"] }),
  ).toEqual(["All That Smolders v1.2", "All That Glitters v2.2.3"]);
  expect(setupPackMapNames({ games: [] })).toEqual([]);
});

test("a blank map name is dropped, since a name is all a map is looked up by", () => {
  expect(setupPackMapNames({ maps: ["", "All That Glitters v2.2.3", null] })).toEqual([
    "All That Glitters v2.2.3",
  ]);
});

test("a pack naming the same map twice installs it once", () => {
  expect(
    setupPackMaps({
      payload: { maps: ["All That Glitters v2.2.3", "All That Glitters v2.2.3"] },
    }),
  ).toEqual(["All That Glitters v2.2.3"]);
});

test("a container with no payload names no maps", () => {
  expect(setupPackMaps(null)).toEqual([]);
  expect(setupPackMaps({})).toEqual([]);
});

test("an engine the pack does not pin is no engine at all", () => {
  // `.spring` is the launcher's own word for "whatever you have", so a pack
  // carrying it pins nothing and shows no engine (issue #176).
  expect(setupPackEngine({ engineVersion: ".spring" })).toBeNull();
  expect(setupPackEngine({ maps: [] })).toBeNull();
  expect(setupPackEngine({ engineVersion: "105.1.1-2590" })).toBe("105.1.1-2590");
});

test("an entry with no archive name is dropped rather than shown blank", () => {
  // `name` is optional on a GameIdentity: an item that pins no build carries
  // only a shortname. There is nothing to print for it in a list of builds.
  expect(
    setupPackGameNames({ games: [{ shortname: "BAR" }, { name: "SplinterFaction 0.1.78" }] }),
  ).toEqual(["SplinterFaction 0.1.78"]);
});

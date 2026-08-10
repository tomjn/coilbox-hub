import { expect, test } from "bun:test";
import { baseMapName, matchMapName } from "./mapName";
import type { BarMap } from "./maps";
import testMaps from "./testMaps.json";

/** Real entries cut from BAR's own list, so the shapes under test are the ones
 * that actually ship. */
const maps = testMaps as BarMap[];

const found = (name: string) => matchMapName(name, maps)?.springName ?? null;

test("an exact spring name matches", () => {
  expect(found("AcidicQuarry 5.17")).toBe("AcidicQuarry 5.17");
});

test("a map BAR does not list matches nothing", () => {
  expect(found("Some Custom Map 1.0")).toBeNull();
  expect(found("")).toBeNull();
});

test("an older build of a map finds the build BAR lists", () => {
  expect(found("Comet Catcher Remake 1.6")).toBe("Comet Catcher Remake 1.8");
});

test("a name with no version at all still finds its map", () => {
  expect(found("Comet Catcher Remake")).toBe("Comet Catcher Remake 1.8");
});

test("two builds of one map resolve to the newer", () => {
  expect(found("Supreme Isthmus v1.4")).toBe("Supreme Isthmus v2.1");
});

test("a suffix that is not a version stays a different map", () => {
  expect(found("All That Glitters v2.2.3")).toBe("All That Glitters v2.2.3");
  expect(found("All That Glitters Extended v1.0.2")).toBe(
    "All That Glitters Extended v1.0.2",
  );
});

test("punctuation and case do not separate two spellings", () => {
  expect(found("acidic_quarry_5.16")).toBe("AcidicQuarry 5.17");
});

test("version parts compare as numbers, not text", () => {
  const versions: BarMap[] = [
    { springName: "Map 1.9", displayName: "Map" },
    { springName: "Map 1.10", displayName: "Map" },
  ];
  expect(matchMapName("Map 1.2", versions)?.springName).toBe("Map 1.10");
});

test("baseMapName strips the version however it is joined on", () => {
  expect(baseMapName("Altair_Crossing_V4.1")).toBe("altaircrossing");
  expect(baseMapName("Ancient Vault v1.4")).toBe("ancientvault");
  expect(baseMapName("AcidicQuarry 5.17")).toBe("acidicquarry");
});

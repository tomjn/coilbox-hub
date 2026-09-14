import { expect, test } from "bun:test";
import {
  gameCountLabel,
  gameCountParts,
  gameTitle,
  itemCardLabel,
  itemCountLabel,
  playAsLabel,
  saysMoreThanName,
} from "./labels";

/**
 * The sentences a page prints about a game (#225). The listing and the game's
 * own page both print them, which is why they are functions rather than lines
 * in markup: two copies would drift the first time somebody edited one.
 */

test("a backfilled game is called by its shortname until anybody names it", () => {
  expect(gameTitle({ shortname: "BA", display_name: null })).toBe("BA");
  expect(gameTitle({ shortname: "BA", display_name: "Balanced Annihilation" })).toBe(
    "Balanced Annihilation",
  );
});

test("the counts read as one sentence, and zero is printed as zero", () => {
  expect(gameCountLabel({ faction_count: 2, unit_count: 340 })).toBe("2 factions, 340 units");
  expect(gameCountLabel({ faction_count: 1, unit_count: 0 })).toBe("1 faction, 0 units");
});

test("community content counts, singular handled", () => {
  expect(itemCountLabel(0)).toBe("0 community items");
  expect(itemCountLabel(1)).toBe("1 community item");
  expect(itemCountLabel(40)).toBe("40 community items");
});

test("the count parts carry the same singular as the sentence", () => {
  expect(gameCountParts({ faction_count: 1, unit_count: 35 })).toEqual([
    { count: 1, noun: "faction" },
    { count: 35, noun: "units" },
  ]);
});

test("a description that only repeats the game's name says nothing", () => {
  const bota = { shortname: "BOTA", display_name: "Basically OTA" };
  expect(saysMoreThanName(bota, "BOTA")).toBe(false);
  expect(saysMoreThanName(bota, " basically ota ")).toBe(false);
  expect(saysMoreThanName(bota, null)).toBe(false);
  expect(saysMoreThanName(bota, "")).toBe(false);
  expect(saysMoreThanName(bota, "A game by Fang")).toBe(true);
});

test("the sides read as a choice, and no sides say nothing", () => {
  expect(playAsLabel(["ARM", "CORE"])).toBe("Play as ARM or CORE.");
  expect(playAsLabel(["Armada", "Cortex", "Legion"])).toBe("Play as Armada, Cortex, or Legion.");
  expect(playAsLabel([])).toBeNull();
});

test("the community card says nothing is published rather than printing zero", () => {
  expect(itemCardLabel(0)).toBe("Nothing published yet");
  expect(itemCardLabel(3)).toBe("3 community items");
});

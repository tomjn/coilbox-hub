import { expect, test } from "bun:test";
import { gameCountLabel, gameTitle } from "./labels";

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

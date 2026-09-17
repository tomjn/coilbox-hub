import { expect, test } from "bun:test";
import { compareGames, type GameSummary } from "./query";

/** A row with only the fields a test cares about. The rest of GameSummary is
 *  filled in so the type holds without every test restating it. */
function game(fields: Partial<GameSummary> & Pick<GameSummary, "shortname">): GameSummary {
  return {
    display_name: null,
    description: null,
    logo_path: null,
    logo_hash: null,
    logo_staged_tier: null,
    featured_at: null,
    download_kind: null,
    download_value: null,
    card_path: null,
    card_hash: null,
    card_staged_tier: null,
    faction_count: 0,
    unit_count: 0,
    item_count: 0,
    ...fields,
  };
}

test("games sort by the name a reader sees, not by the shortname", () => {
  const sorted = [
    game({ shortname: "ZK", display_name: "Zero-K" }),
    game({ shortname: "BAR", display_name: "Beyond All Reason" }),
    game({ shortname: "AA", display_name: "Metal Factions" }),
  ]
    .sort(compareGames)
    .map((row) => row.shortname);

  // AA lands in the middle despite its shortname, because Metal Factions is
  // what the card says.
  expect(sorted).toEqual(["BAR", "AA", "ZK"]);
});

test("a game with no display name sorts on its shortname", () => {
  const sorted = [game({ shortname: "Zed" }), game({ shortname: "Alpha" })]
    .sort(compareGames)
    .map((row) => row.shortname);

  expect(sorted).toEqual(["Alpha", "Zed"]);
});

test("featured games come first, alphabetical among themselves", () => {
  const sorted = [
    game({ shortname: "AAA" }),
    game({ shortname: "ZZZ", featured_at: "2026-09-18T00:00:00Z" }),
    game({ shortname: "MMM", featured_at: "2026-09-17T00:00:00Z" }),
  ]
    .sort(compareGames)
    .map((row) => row.shortname);

  // The older feature does not win. Inside the block it is alphabetical, so
  // nobody has to keep a rank in order.
  expect(sorted).toEqual(["MMM", "ZZZ", "AAA"]);
});

test("unit count no longer decides the order", () => {
  const sorted = [
    game({ shortname: "small", unit_count: 2 }),
    game({ shortname: "huge", unit_count: 900 }),
  ]
    .sort(compareGames)
    .map((row) => row.shortname);

  expect(sorted).toEqual(["huge", "small"]);
});

test("two games with the same title are told apart by shortname", () => {
  const sorted = [
    game({ shortname: "BB", display_name: "Same" }),
    game({ shortname: "AA", display_name: "Same" }),
  ]
    .sort(compareGames)
    .map((row) => row.shortname);

  expect(sorted).toEqual(["AA", "BB"]);
});

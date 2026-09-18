import { expect, test } from "bun:test";
import { staticTierUrl } from "@/lib/assets/cdn";
import type { GameSummary } from "@/lib/games/query";
import { buildGameListBody, GAME_LIST_FORMAT, GAME_LIST_VERSION } from "./gameList";

function game(fields: Partial<GameSummary> & Pick<GameSummary, "shortname">): GameSummary {
  return {
    display_name: null,
    description: null,
    logo_path: null,
    logo_hash: null,
    logo_staged_tier: null,
    featured_at: null,
    downloads: [],
    card_path: null,
    card_hash: null,
    card_staged_tier: null,
    faction_count: 0,
    unit_count: 0,
    item_count: 0,
    ...fields,
  };
}

test("the envelope names the same format the other game routes use", () => {
  const body = buildGameListBody([]);
  expect(body.format).toBe(GAME_LIST_FORMAT);
  expect(body.version).toBe(GAME_LIST_VERSION);
  expect(GAME_LIST_FORMAT).toBe("coilbox-hub-games");
  expect(body.games).toEqual([]);
});

test("a bare game answers nulls rather than being left out", () => {
  const [row] = buildGameListBody([game({ shortname: "BA" })]).games;
  expect(row).toEqual({
    shortname: "BA",
    title: "BA",
    description: null,
    featured: false,
    downloads: [],
    logo: null,
    card: null,
    faction_count: 0,
    unit_count: 0,
    item_count: 0,
  });
});

test("the title falls back to the shortname the way every page does", () => {
  const [row] = buildGameListBody([
    game({ shortname: "BAR", display_name: "Beyond All Reason" }),
  ]).games;
  expect(row.title).toBe("Beyond All Reason");
});

test("downloads are published as kinds and values, not as addresses", () => {
  const [row] = buildGameListBody([
    game({
      shortname: "MF",
      downloads: [
        { kind: "github", value: "springraaar/metal_factions", asset: "metal_factions" },
        { kind: "rapid", value: "metalfactions:stable" },
      ],
    }),
  ]).games;
  // The caller decides what a rapid tag means. Handing it a link here would
  // mean inventing one. The order is the order to try, so it is published as
  // given rather than sorted into some shape of the hub's own.
  expect(row.downloads).toEqual([
    { kind: "github", value: "springraaar/metal_factions", asset: "metal_factions" },
    { kind: "rapid", value: "metalfactions:stable" },
  ]);
});

test("a stored source the form would refuse today is still published", () => {
  // A url source carried over from the single column has no filename. It is
  // the address its owner recorded, and dropping it here would take a game's
  // only download off the listing without telling anybody.
  const [row] = buildGameListBody([
    game({ shortname: "MF", downloads: [{ kind: "url", value: "https://example.test/mf.sdz" }] }),
  ]).games;
  expect(row.downloads).toEqual([{ kind: "url", value: "https://example.test/mf.sdz" }]);
});

test("promoted art is published as its durable address", () => {
  const [row] = buildGameListBody([
    game({ shortname: "BA", card_path: "games/BA/card.webp" }),
  ]).games;
  expect(row.card).toBe(staticTierUrl("games/BA/card.webp"));
});

test("featured is a flag, not a timestamp", () => {
  const [row] = buildGameListBody([
    game({ shortname: "BA", featured_at: "2026-09-18T00:00:00Z" }),
  ]).games;
  expect(row.featured).toBe(true);
});

test("the order the listing was given is the order published", () => {
  // The route hands over what `fetchGames` sorted, so a caller drawing the list
  // in order gets the featured block first without having to know the rule.
  const body = buildGameListBody([
    game({ shortname: "Pinned", featured_at: "2026-09-18T00:00:00Z" }),
    game({ shortname: "Ordinary" }),
  ]);
  expect(body.games.map((row) => row.shortname)).toEqual(["Pinned", "Ordinary"]);
});

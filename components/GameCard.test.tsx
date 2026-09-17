import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { GameCard } from "@/components/GameCard";
import type { GameSummary } from "@/lib/games/query";
import type { GameSides } from "@/lib/games/sides";

const GAME: GameSummary = {
  shortname: "BA",
  display_name: "Balanced Annihilation",
  description: "The classic total annihilation balance mod.",
  logo_path: null,
  logo_hash: null,
  logo_staged_tier: null,
  featured_at: null,
  download_kind: null,
  download_value: null,
  card_path: null,
  card_hash: null,
  card_staged_tier: null,
  faction_count: 2,
  unit_count: 340,
  item_count: 12,
};

test("a card leads with the name and links to the game's page", () => {
  const html = renderToStaticMarkup(<GameCard game={GAME} />);
  expect(html).toContain("Balanced Annihilation");
  expect(html).toContain('href="/games/BA"');
});

/** Only the name is inside the link, so a screen reader announces the link as
 *  the game's name rather than its whole description (#358). */
test("the card's link is named by the game's name alone, inside its heading", () => {
  const html = renderToStaticMarkup(<GameCard game={GAME} />);
  expect(html).toMatch(/<h2[^>]*><a [^>]*href="\/games\/BA"[^>]*>Balanced Annihilation<\/a><\/h2>/);
});

test("a game with no description yet shows none rather than an empty block", () => {
  const html = renderToStaticMarkup(
    <GameCard game={{ ...GAME, description: null }} />,
  );
  expect(html).not.toContain("line-clamp-3");
});

/** The counts are the one number a card carries, so it is the sentence a
 *  player uses rather than two bare figures. Community items live on the
 *  game's own page, not on the shelf (#280). */
test("a card says how much there is in words", () => {
  const html = renderToStaticMarkup(<GameCard game={GAME} />);
  expect(html.replace(/<[^>]+>/g, "")).toContain("2 factions, 340 units");
  expect(html).not.toContain("community item");
});

/** A game the hub holds a logo for draws it beside the name (#239), from the
 *  durable tier, in a tile of one size. One that holds none gets the same tile
 *  with no picture in it, so names still line up across a row (#358). */
test("a card with a logo draws it in the tile, and one without gets an empty tile", () => {
  const withLogo = renderToStaticMarkup(
    <GameCard
      game={{ ...GAME, shortname: "SF", logo_path: "games/SF/logo.webp" }}
    />,
  );
  expect(withLogo).toContain(
    'src="https://tomjn.github.io/coilbox-assets/games/SF/logo.webp"',
  );
  expect(withLogo).toContain("h-16 w-24");

  const withoutLogo = renderToStaticMarkup(<GameCard game={GAME} />);
  expect(withoutLogo).not.toContain("<img");
  expect(withoutLogo).toContain("h-16 w-24");
});

/** A logo still in the staging bucket is drawn from the hub's own route, named
 *  by its hash, because GitHub Pages does not have it until promotion (#345).
 *  An unrecognised staged tier is not drawn at all. */
test("a staged logo comes from the hub's route, and an unrecognised staged tier is not drawn", () => {
  const hash = "a".repeat(64);
  const staged = { ...GAME, shortname: "SF", logo_path: "games/SF/logo.webp", logo_hash: hash };

  expect(
    renderToStaticMarkup(<GameCard game={{ ...staged, logo_staged_tier: "bucket" }} />),
  ).toContain(`src="/assets/games/SF/logo/${hash}"`);

  expect(
    renderToStaticMarkup(<GameCard game={{ ...staged, logo_staged_tier: "unknown" }} />),
  ).not.toContain("<img");
});

const SIDES: GameSides = {
  factions: [
    { key: "arm", name: "ARM" },
    { key: "core", name: "CORE" },
  ],
  commanders: new Map([
    [
      "arm",
      {
        unit_name: "armcom",
        label: "Commander",
        picture: {
          from: "static",
          url: "https://tomjn.github.io/coilbox-assets/units/BA/armcom.webp",
          served: { keyedOn: "unit", game: "BA", unitName: "armcom", variant: "buildpic" },
          substituted: false,
          width: 64,
          height: 64,
        },
      },
    ],
  ]),
};

/** A description that is only the game's own name reads as a placeholder, so
 *  the card says which sides a player can pick instead. */
test("a game with no real description says which sides a player can pick", () => {
  for (const description of [null, "BA", "balanced annihilation"]) {
    const html = renderToStaticMarkup(<GameCard game={{ ...GAME, description }} sides={SIDES} />);
    expect(html).toContain("Play as ARM or CORE.");
  }
  const described = renderToStaticMarkup(<GameCard game={GAME} sides={SIDES} />);
  expect(described).not.toContain("Play as");
});

test("the foot of the card draws each side's start unit it holds a picture for", () => {
  const html = renderToStaticMarkup(<GameCard game={GAME} sides={SIDES} />);
  expect(html).toContain('src="https://tomjn.github.io/coilbox-assets/units/BA/armcom.webp"');
  expect(html.match(/coilbox-assets\/units/g)).toHaveLength(1);

  const none = renderToStaticMarkup(
    <GameCard game={GAME} sides={{ ...SIDES, commanders: new Map() }} />,
  );
  expect(none).not.toContain("<img");
});

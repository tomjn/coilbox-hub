import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { GameCard } from "@/components/GameCard";
import type { GameSummary } from "@/lib/games/query";

const GAME: GameSummary = {
  shortname: "BA",
  display_name: "Balanced Annihilation",
  description: "The classic total annihilation balance mod.",
  logo_path: null,
  faction_count: 2,
  unit_count: 340,
  item_count: 12,
};

test("a card leads with the name and links to the game's page", () => {
  const html = renderToStaticMarkup(<GameCard game={GAME} />);
  expect(html).toContain("Balanced Annihilation");
  expect(html).toContain('href="/games/BA"');
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
  expect(html).toContain("2 factions, 340 units");
  expect(html).not.toContain("community item");
});

/** A game the hub holds a logo for draws it above the name (#239), from the
 *  durable tier. One that holds none keeps the typographic card. */
test("a card with a logo draws it, and one without does not", () => {
  const withLogo = renderToStaticMarkup(
    <GameCard
      game={{ ...GAME, shortname: "SF", logo_path: "games/SF/logo.webp" }}
    />,
  );
  expect(withLogo).toContain(
    'src="https://tomjn.github.io/coilbox-assets/games/SF/logo.webp"',
  );

  expect(renderToStaticMarkup(<GameCard game={GAME} />)).not.toContain("<img");
});

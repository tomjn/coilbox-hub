import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { GameCard } from "@/components/GameCard";
import type { GameSummary } from "@/lib/games/query";

const GAME: GameSummary = {
  shortname: "BA",
  display_name: "Balanced Annihilation",
  description: "The classic total annihilation balance mod.",
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
 *  player uses rather than two bare figures. */
test("a card says how much there is in words", () => {
  const html = renderToStaticMarkup(<GameCard game={GAME} />);
  expect(html).toContain("2 factions, 340 units · 12 community items");
});

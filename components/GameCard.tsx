import Link from "next/link";
import { gameCountLabel, gameTitle, itemCountLabel } from "@/lib/games/labels";
import type { GameSummary } from "@/lib/games/query";

/**
 * One game in the catalog listing (#225).
 *
 * A typographic card, deliberately. A map card leads with a picture because a
 * map is a shape and a reader knows the one they want by looking at it; a game
 * is known by name, and its logo is a column that does not exist until #229
 * adds it. Drawing an empty frame now would promise a picture nobody can have
 * yet, so the card spends its space on what a row actually carries: the name,
 * what the game is, and how much of it there is to read about.
 *
 * When logos land, the frame goes above the heading and nothing else here
 * moves.
 */

/** A description held to three lines, so one verbose blurb cannot set the
 *  height of every card in its row. */
const BLURB = "line-clamp-3 text-sm leading-6 text-neutral-400";

export function GameCard({ game }: { game: GameSummary }) {
  return (
    <li>
      <Link
        href={`/games/${game.shortname}`}
        className="group flex h-full flex-col gap-2 rounded-md border border-neutral-900 p-4 transition-colors hover:border-neutral-600 active:border-neutral-500"
      >
        <span className="text-lg font-semibold tracking-tight text-neutral-100 transition-colors group-hover:text-white group-active:text-white">
          {gameTitle(game)}
        </span>
        {game.description ? <span className={BLURB}>{game.description}</span> : null}
        <span className="mt-auto text-xs uppercase tracking-wide text-neutral-500">
          {gameCountLabel(game)} &middot; {itemCountLabel(game.item_count)}
        </span>
      </Link>
    </li>
  );
}

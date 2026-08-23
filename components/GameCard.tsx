import Link from "next/link";
import { staticTierUrl } from "@/lib/assets/cdn";
import { gameCountLabel, gameTitle } from "@/lib/games/labels";
import type { GameSummary } from "@/lib/games/query";

/**
 * One game in the catalog listing (#225).
 *
 * A map card leads with a picture because a map is a shape and a reader knows
 * the one they want by looking at it; a game is known by name. But a logo is
 * the mark a player recognises a game by in a launcher, and since ownership
 * (#229) put one on the row, a card that holds one draws it above the heading
 * (#239). The picture is decorative for exactly the reason the name is not: a
 * card with no logo keeps the typographic look every card had before, and the
 * name beside or under it says what it is.
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
        {/* Not next/image: logos come off the durable tier already sized for
            where they are drawn, the same reason buildpics skip it. */}
        {game.logo_path ? (
          // eslint-disable-next-line @next/next/no-img-element -- see above
          <img
            src={staticTierUrl(game.logo_path)}
            alt=""
            loading="lazy"
            decoding="async"
            className="h-12 w-auto self-start object-contain"
          />
        ) : null}
        <span className="text-lg font-semibold tracking-tight text-neutral-100 transition-colors group-hover:text-white group-active:text-white">
          {gameTitle(game)}
        </span>
        {game.description ? <span className={BLURB}>{game.description}</span> : null}
        <span className="mt-auto text-xs uppercase tracking-wide text-neutral-500">
          {gameCountLabel(game)}
        </span>
      </Link>
    </li>
  );
}

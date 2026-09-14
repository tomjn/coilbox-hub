import Link from "next/link";
import { Fragment } from "react";
import { CommanderPicture } from "@/components/CommanderPicture";
import { GameLogo } from "@/components/GameLogo";
import { RichTextInline } from "@/components/RichText";
import { gameArtUrl } from "@/lib/games/art";
import { gameCountParts, gameTitle, playAsLabel, saysMoreThanName } from "@/lib/games/labels";
import type { GameSummary } from "@/lib/games/query";
import type { GameSides } from "@/lib/games/sides";
import { richTextToPlainText } from "@/lib/text/richText";

/**
 * One game in the catalog listing (#225).
 *
 * A map card leads with a picture because a map is a shape and a reader knows
 * the one they want by looking at it. A game is known by name, with its logo as
 * the mark a player recognises it by in a launcher (#239).
 *
 * ## Every card is the same shape (#358)
 *
 * The logo sits in a fixed tile beside the name, the way the game's own page
 * draws it, and a game with no logo gets an empty tile, so names line up across
 * a row. The counts sit right under the name rather than at the foot of the
 * card, so a game with no description ends in space at the bottom instead of a
 * gap between its name and its counts.
 *
 * ## What the game is, not only what it is called
 *
 * The foot of the card is a row of the units each side starts with, drawn from
 * their buildpics: a BAR commander and a Spring 1944 army HQ tell two games
 * apart where two logos only name them. A game with no description, or one
 * that only repeats its name, says which sides a player can pick instead of
 * leaving a hole where the blurb goes.
 *
 * ## The name is the link
 *
 * The whole card is clickable, but only the name is inside the link, stretched
 * over the card with `after:`. A link wrapping the whole card was announced with
 * the full description as its name, which for some games runs to paragraphs.
 * The focus ring is drawn on the card, so a keyboard user sees what they are on.
 */

/** A description held to three lines, so one verbose blurb cannot set the
 *  height of every card in its row. */
const BLURB = "line-clamp-3 text-sm leading-6 text-neutral-400";

export function GameCard({ game, sides }: { game: GameSummary; sides?: GameSides }) {
  const logo = gameArtUrl(game.shortname, "logo", {
    path: game.logo_path,
    hash: game.logo_hash,
    staged_tier: game.logo_staged_tier,
  });
  const describes =
    game.description !== null && saysMoreThanName(game, richTextToPlainText(game.description));
  const playAs = sides ? playAsLabel(sides.factions.map((faction) => faction.name)) : null;
  const commanders = sides
    ? sides.factions.flatMap((faction) => sides.commanders.get(faction.key) ?? [])
    : [];

  return (
    <li className="group relative flex h-full flex-col gap-4 rounded-md border border-neutral-800 bg-neutral-950 p-4 transition-colors hover:border-neutral-600 has-[a:active]:border-neutral-500 has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-2 has-[a:focus-visible]:outline-neutral-300">
      <div className="flex items-start gap-4">
        {/* Decorative here: the name beside it says which game this is. */}
        <GameLogo src={logo} alt="" />
        <div className="flex min-w-0 flex-col gap-1">
          <h2 className="break-words text-lg font-semibold leading-snug tracking-tight text-balance text-neutral-100 transition-colors group-hover:text-white">
            <Link
              href={`/games/${game.shortname}`}
              className="after:absolute after:inset-0 after:rounded-md focus-visible:outline-none"
            >
              {gameTitle(game)}
            </Link>
          </h2>
          <p className="text-sm tabular-nums text-neutral-400">
            {gameCountParts(game).map((part, index) => (
              <Fragment key={part.noun}>
                {index > 0 ? ", " : null}
                <span className="font-medium text-neutral-100">{part.count}</span> {part.noun}
              </Fragment>
            ))}
          </p>
        </div>
      </div>
      {describes && game.description ? (
        <p className={BLURB}>
          <RichTextInline text={game.description} />
        </p>
      ) : playAs ? (
        <p className={BLURB}>{playAs}</p>
      ) : null}
      {commanders.length > 0 ? (
        // Overlapped, so the nine sides Spring 1944 has still fit one row of
        // the narrowest card. Each picture is ringed in the card's own colour so
        // the overlap reads as a line up rather than a smear.
        <ul aria-hidden className="mt-auto flex -space-x-2.5">
          {commanders.map((commander) => (
            <li key={commander.unit_name}>
              <CommanderPicture
                commander={commander}
                className="size-9 bg-black ring-2 ring-neutral-950"
              />
            </li>
          ))}
        </ul>
      ) : null}
    </li>
  );
}

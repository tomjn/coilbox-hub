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
 * ## Card art on top, where a game has it
 *
 * A game can upload 16:9 art, the same shape coilbox draws its own game cards
 * from, and it fills the head of the card. A game without it gets the layout
 * below unchanged, so this only ever adds.
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
 * apart where two logos only name them. A game whose start units nobody has
 * reported draws three of its units at random instead, and a unit with no
 * buildpic held yet draws the dashed outline the rest of the site uses for a
 * picture that does not exist. A game with no description, or one that only
 * repeats its name, says which sides a player can pick instead of leaving a
 * hole where the blurb goes.
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
  const card = gameArtUrl(game.shortname, "card", {
    path: game.card_path,
    hash: game.card_hash,
    staged_tier: game.card_staged_tier,
  });
  const describes =
    game.description !== null && saysMoreThanName(game, richTextToPlainText(game.description));
  const playAs = sides ? playAsLabel(sides.factions.map((faction) => faction.name)) : null;
  const commanders = sides
    ? sides.factions.flatMap((faction) => sides.commanders.get(faction.key) ?? [])
    : [];
  // A game that has never reported its start units falls back to a few of its
  // own units, picked at random. `sample` is empty whenever the commanders are
  // not, so this is one row or the other.
  const units = commanders.length > 0 ? commanders : (sides?.sample ?? []);

  return (
    <li className="group relative flex h-full flex-col gap-4 rounded-md border border-neutral-800 bg-card p-4 transition-colors hover:border-neutral-600 has-[a:active]:border-neutral-500 has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-2 has-[a:focus-visible]:outline-neutral-300">
      {card ? (
        // Pulled out to the card's own edges, since a picture inset inside a
        // padded card reads as a thumbnail rather than as the card's face. A
        // fixed 16:9 box so a row lines up whatever each game uploaded, and
        // cropped rather than letterboxed, because a band of background inside
        // a card reads as a mistake. Decorative, like the logo: the name under
        // it says which game this is.
        // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image, see next.config.ts
        <img
          src={card}
          alt=""
          // `max-w-none` because preflight caps every image at `max-width:
          // 100%`, which is the padded width and clamps the calc below back to
          // it, leaving the picture 2rem short of the card's right edge. The
          // underscores are Tailwind's escape for the spaces `calc` needs
          // around its `+`.
          className="-mx-4 -mt-4 aspect-video w-[calc(100%_+_2rem)] max-w-none rounded-t-md object-cover"
        />
      ) : null}
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
      {units.length > 0 ? (
        // Overlapped, so the nine sides Spring 1944 has still fit one row of
        // the narrowest card. Each picture is ringed in the card's own colour so
        // the overlap reads as a line up rather than a smear.
        <ul aria-hidden className="mt-auto flex -space-x-2.5">
          {units.map((commander) => (
            <li key={commander.unit_name}>
              <CommanderPicture
                commander={commander}
                // The card colour composited over the page, rather than
                // `ring-card` itself. The ring exists to mask the portrait
                // underneath it, and a half transparent ring shows that
                // portrait through, which is the smear the overlap is meant to
                // avoid.
                className="size-9 bg-black ring-2 ring-[#131314]"
              />
            </li>
          ))}
        </ul>
      ) : null}
    </li>
  );
}

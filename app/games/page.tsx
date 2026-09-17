import type { Metadata } from "next";
import Link from "next/link";
import { connection } from "next/server";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { games } from "@/components/art/drawings";
import { GameCard } from "@/components/GameCard";
import { gameSidesCached, gamesListing } from "@/lib/games/cached";
import type { GameSummary } from "@/lib/games/query";
import type { GameSides } from "@/lib/games/sides";

/**
 * Every game the hub knows about (#225).
 *
 * The maps listing's shape with less in it: no filters, because a catalog of
 * games is a shelf rather than a warehouse, and no pager, because it fits on
 * one. What it keeps from /maps is the part that matters - the whole page is
 * ordinary links and server markup, so it works with scripting off and the URL
 * is the state.
 *
 * ## Read with the visitor's own client
 *
 * `public.game_browse` is a view over tables that carry read-all policies, and
 * it is security invoker, so the listing comes back through row level security
 * with nothing but the publishable key. Nothing here needs the secret key,
 * which is why the cached read builds an anonymous client.
 */

export const metadata: Metadata = {
  title: "Games - Coilbox Hub",
  description: "Browse the games the hub holds facts about: their factions, their units and their build trees.",
};

/** A shelf of cases, one pulled forward and lit: the drawing coilbox's own
 *  catalog page uses for this exact thing. */
const BACKDROP_STRENGTH = 0.05;

/**
 * One block of cards.
 *
 * Two of these with a rule between them, rather than one grid with a divider
 * item inside it. A presentational item in a list of games is a lie to a
 * screen reader, and a list per block means `auto-rows-fr` applies per block,
 * so a tall featured card no longer sets the row height of everything under
 * it.
 */
function GameGrid({
  heading,
  rows,
  sides,
}: {
  heading: string;
  rows: GameSummary[];
  sides: Map<string, GameSides>;
}) {
  return (
    <section className="flex flex-col gap-4">
      {/* The blocks are told apart by the rule and the order, which a sighted
          reader can see and a screen reader cannot. */}
      <h2 className="sr-only">{heading}</h2>
      {/* Rows size to their own content rather than all matching the tallest in
          the grid. `h-full` on each card still squares up the cards within a
          row, which is what the eye reads. Matching every row was fine when
          cards differed by a line of description. Card art makes that gap about
          200px, and a card with that much empty space under its text looks
          broken rather than aligned. */}
      <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {rows.map((game) => (
          <GameCard key={game.shortname} game={game} sides={sides.get(game.shortname)} />
        ))}
      </ul>
    </section>
  );
}

export default async function Games() {
  // Defer to request time, like every other page that reads the catalog. The
  // header's session read makes the shell dynamic anyway; without this marker
  // Next would try to prerender the listing at build, where a deployment with
  // no Supabase configured would be asked for data it cannot reach. The read
  // itself is still held between requests by "use cache".
  await connection();

  const { games: rows, error } = await gamesListing();
  const sides = await gameSidesCached(rows.map((game) => game.shortname));

  // `compareGames` has already put the featured ones first, so this is a split
  // rather than a second sort.
  const featured = rows.filter((game) => game.featured_at !== null);
  const rest = rows.slice(featured.length);

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={games} strength={BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <div className="flex flex-col gap-2">
          <h1 className="text-3xl font-semibold tracking-tight">Games</h1>
          <p className="text-neutral-400">
            The games people play, what they are called, and every unit in them.
          </p>
        </div>

        {error ? (
          <p className="text-sm text-red-400">
            The catalog could not be read just now. Try again in a moment.
          </p>
        ) : rows.length === 0 ? (
          <div className="rounded-md border border-neutral-800 bg-card p-8 text-center">
            <p className="text-sm text-neutral-400">
              The hub holds facts about no games yet.
            </p>
          </div>
        ) : (
          // Featured above the rule, everything else below it. Both blocks
          // disappear when they are empty, so a hub with nothing featured
          // draws one grid and no rule.
          <div className="flex flex-col gap-6">
            {featured.length > 0 ? (
              <GameGrid heading="Featured games" rows={featured} sides={sides} />
            ) : null}
            {featured.length > 0 && rest.length > 0 ? <hr className="border-neutral-900" /> : null}
            {rest.length > 0 ? <GameGrid heading="All games" rows={rest} sides={sides} /> : null}
          </div>
        )}

        <p className="text-sm text-neutral-400">
          Looking for a map instead?{" "}
          <Link href="/maps" className="text-neutral-300 underline-offset-4 hover:underline active:underline">
            Every map the hub knows about
          </Link>
          .
        </p>
      </div>
    </main>
  );
}

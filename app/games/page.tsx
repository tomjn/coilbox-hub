import type { Metadata } from "next";
import Link from "next/link";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { games } from "@/components/art/drawings";
import { GameCard } from "@/components/GameCard";
import { gamesListing } from "@/lib/games/cached";

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

export default async function Games() {
  const { games: rows, error } = await gamesListing();

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
          <div className="rounded-md border border-neutral-800 bg-neutral-950 p-8 text-center">
            <p className="text-sm text-neutral-400">
              The hub holds facts about no games yet.
            </p>
          </div>
        ) : (
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {rows.map((game) => (
              <GameCard key={game.shortname} game={game} />
            ))}
          </ul>
        )}

        <p className="text-sm text-neutral-500">
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

import { gamesListing } from "@/lib/games/cached";

/**
 * Every listed game's shortname, so `next build` renders each game's pages
 * whole, and the units, tree and changes pages under them can be upgraded to
 * whole static pages after their first visit. Next resolves params in route
 * order, so a unit page can only be upgraded once its game's shortname is
 * known.
 *
 * Cache Components refuses an empty list. A build with no Supabase settings,
 * as CI runs, lists no games, so it gets one placeholder, which renders as not
 * found.
 */
export async function generateStaticParams() {
  const { games } = await gamesListing();
  if (games.length === 0) return [{ shortname: "__none__" }];
  return games.map(({ shortname }) => ({ shortname }));
}

export default function GameLayout({ children }: LayoutProps<"/games/[shortname]">) {
  return children;
}

import { gameArtUrl } from "@/lib/games/art";
import type { DownloadKind } from "@/lib/games/download";
import { gameTitle } from "@/lib/games/labels";
import type { GameSummary } from "@/lib/games/query";

/**
 * The wire shape of the games listing.
 *
 * The facts route takes a game's numbers in and the branding route takes its
 * pictures in. This is the read the other way: every game the hub holds, with
 * enough on each to draw a card and fetch the game. A lobby drawing a "games
 * you could install" screen had to scrape the HTML listing until now.
 *
 * The envelope is `coilbox-hub-games`, the same string both submission routes
 * carry. All three talk to the same client about the same games, and a second
 * format negotiation buys nothing.
 *
 * ## Why no token
 *
 * Every field here describes a page `/games` already serves in public. A
 * listing that demanded an account would put a lobby's download screen behind a
 * sign in, which is the rung of coilbox's ladder that has no account yet.
 *
 * ## Why the download is a kind and a value
 *
 * Resolving a rapid tag to an address is not something the hub can do. The
 * caller hands the tag to its own downloader, so the honest answer is the pair
 * that was stored, and the caller decides what a kind means.
 *
 * ## One limit worth knowing
 *
 * A picture still waiting for promotion resolves to a root relative path
 * (`/assets/games/...`) rather than a full address, because that is the hub's
 * own route. A caller resolves it against the host it just called. It is left
 * that way rather than made absolute here, since nothing at this layer knows
 * the hub's public origin and a guessed one would be worse than a path.
 */

export const GAME_LIST_FORMAT = "coilbox-hub-games";
export const GAME_LIST_VERSION = 1;

export interface GameListEntry {
  shortname: string;
  /** What to print: the display name where one is held, the shortname
   *  otherwise, which is the fallback every page here already makes. */
  title: string;
  description: string | null;
  featured: boolean;
  download: { kind: DownloadKind; value: string } | null;
  /** Resolved addresses, so a caller never has to know about tiers or staging.
   *  The banner is not here: it is not a column `public.game_browse` publishes,
   *  and a field that is structurally always null is worse than no field. */
  logo: string | null;
  card: string | null;
  faction_count: number;
  unit_count: number;
  item_count: number;
}

export interface GameListResponseBody {
  format: typeof GAME_LIST_FORMAT;
  version: typeof GAME_LIST_VERSION;
  games: GameListEntry[];
}

/** The listing as JSON, in the order it was given, which is the order the page
 *  draws: featured first, then alphabetical. */
export function buildGameListBody(games: GameSummary[]): GameListResponseBody {
  return {
    format: GAME_LIST_FORMAT,
    version: GAME_LIST_VERSION,
    games: games.map((game) => ({
      shortname: game.shortname,
      title: gameTitle(game),
      description: game.description,
      featured: game.featured_at !== null,
      download:
        game.download_kind && game.download_value
          ? { kind: game.download_kind as DownloadKind, value: game.download_value }
          : null,
      logo: gameArtUrl(game.shortname, "logo", {
        path: game.logo_path,
        hash: game.logo_hash,
        staged_tier: game.logo_staged_tier,
      }),
      card: gameArtUrl(game.shortname, "card", {
        path: game.card_path,
        hash: game.card_hash,
        staged_tier: game.card_staged_tier,
      }),
      faction_count: game.faction_count,
      unit_count: game.unit_count,
      item_count: game.item_count,
    })),
  };
}

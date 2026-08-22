import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { cache } from "react";
import { requestOwnership, setGameVisibility } from "@/app/games/actions";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { games } from "@/components/art/drawings";
import { staticTierUrl } from "@/lib/assets/cdn";
import { gameCountLabel, gameTitle, itemCountLabel } from "@/lib/games/labels";
import { gamePageCached } from "@/lib/games/cached";
import type { GamePageFaction } from "@/lib/games/page";
import { createClient } from "@/lib/supabase/server";

/**
 * Everything the catalog holds about one game (#226).
 *
 * Addressed by shortname, which is identity: the same value `public.asset.game`
 * files a buildpic under and `public.item.game_key` groups items by. A page that
 * addressed games by anything else would be a third spelling of the one answer.
 *
 * ## Read with the visitor's own client
 *
 * There is no licence gate in front of a game, so unlike the map page nothing
 * here needs the secret key. Every table the read touches grants select to
 * `anon`, and `gamePageCached` holds the whole answer between requests.
 */

/** Once per request, and held between them.
 *
 *  `gamePageCached` is what does the holding. `cache` on top of it is for the
 *  two calls inside one request: `generateMetadata` and the page both ask, and
 *  without it the second ask walks back into the cached function rather than
 *  reusing the answer the first already has. */
const load = cache(async (shortname: string) => gamePageCached(shortname));

const BACKDROP_STRENGTH = 0.05;

export async function generateMetadata({
  params,
}: {
  params: Promise<{ shortname: string }>;
}): Promise<Metadata> {
  const page = await load((await params).shortname);
  if (!page) return { title: "Not found - Coilbox Hub" };

  const title = gameTitle(page);
  const description =
    page.description ??
    `${page.faction_count} factions and ${page.unit_count} units on the hub.`;

  return {
    title: `${title} - Coilbox Hub`,
    description,
    openGraph: { title, description, type: "article" },
  };
}

/** One side of the game, as a chip beside its fellows. The name is always
 *  there; the logo rides along when the hub holds one, because a strip of
 *  dashed boxes would promise pictures nobody has yet. The whole chip is a
 *  link into the encyclopedia, filtered to that side (#280). */
function Faction({ game, faction }: { game: string; faction: GamePageFaction }) {
  return (
    <li>
      <Link
        href={`/games/${game}/units?faction=${encodeURIComponent(faction.key)}`}
        className="flex items-center gap-2 rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-200 underline-offset-4 transition-colors hover:border-neutral-600 hover:text-white active:border-neutral-500 active:text-white"
      >
        {faction.logo_path ? (
          // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image; see next.config.ts
          <img
            src={staticTierUrl(faction.logo_path)}
            alt=""
            width={24}
            height={24}
            loading="lazy"
            decoding="async"
            className="h-6 w-6 rounded object-contain"
          />
        ) : null}
        <span>{faction.name}</span>
      </Link>
    </li>
  );
}

export default async function Game({ params }: { params: Promise<{ shortname: string }> }) {
  const { shortname } = await params;
  const page = await load(shortname);
  if (!page) notFound();

  const title = gameTitle(page);

  // The session decides which of the three ownership states the visitor sees:
  // an unowned game asks for somebody to take it, the owner's own game offers
  // the pen, and a game owned by somebody else says nothing, because who owns a
  // game is not a fact a visitor needs.
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  const isOwner = user !== null && page.owner_user_id === user.id;
  let mayHide = false;
  if (user) {
    if (isOwner) {
      mayHide = true;
    } else {
      const { data: allowed } = await supabase.rpc("is_moderator");
      mayHide = allowed === true;
    }
  }

  return (
    <main className="relative flex-1">
      {page.banner_path ? (
        // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image; see next.config.ts
        <img
          src={staticTierUrl(page.banner_path)}
          alt=""
          decoding="async"
          className="h-40 w-full object-cover sm:h-56"
        />
      ) : null}
      <ArtBackdrop drawing={games} strength={page.banner_path ? 0 : BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <div className="flex flex-col gap-3">
          <div className="flex items-center gap-4">
            {page.logo_path ? (
              // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image; see next.config.ts
              <img
                src={staticTierUrl(page.logo_path)}
                alt={`${title} logo`}
                width={64}
                height={64}
                decoding="async"
                className="h-16 w-16 rounded object-contain"
              />
            ) : null}
            <div className="flex flex-col">
              <p className="text-xs uppercase tracking-wide text-neutral-500">{page.shortname}</p>
              <h1 className="text-3xl font-semibold tracking-tight">{title}</h1>
            </div>
          </div>
          {page.description ? (
            <p className="max-w-3xl text-neutral-300">{page.description}</p>
          ) : null}
          {page.release ? (
            <p className="text-sm text-neutral-500">Facts as of release {page.release}.</p>
          ) : null}
          {isOwner ? (
            <p className="text-sm">
              <Link
                href={`/games/${shortname}/edit`}
                className="text-neutral-300 underline-offset-4 hover:underline active:underline"
              >
                Edit this game&rsquo;s words and images
              </Link>
              .
            </p>
          ) : null}
          {mayHide ? (
            <form action={setGameVisibility} className="pt-1">
              <input type="hidden" name="shortname" value={shortname} />
              <input type="hidden" name="hidden" value="true" />
              <button
                type="submit"
                className="rounded-md border border-neutral-800 px-3 py-1.5 text-sm text-neutral-400 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200"
              >
                Hide this game
              </button>
            </form>
          ) : null}
        </div>

        {page.links.length > 0 ? (
          <ul className="flex flex-wrap gap-2">
            {page.links.map((link) => (
              <li key={link.url}>
                <a
                  href={link.url}
                  rel="noopener noreferrer"
                  className="rounded-md border border-neutral-800 px-3 py-1.5 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
                >
                  {link.label}
                </a>
              </li>
            ))}
          </ul>
        ) : null}

        <section className="flex flex-col gap-3" aria-labelledby="game-factions">
          <h2 id="game-factions" className="text-sm uppercase tracking-wide text-neutral-400">
            Factions
          </h2>
          {page.factions.length > 0 ? (
            <ul className="flex flex-wrap gap-2">
              {page.factions.map((faction) => (
                <Faction key={faction.key} game={page.shortname} faction={faction} />
              ))}
            </ul>
          ) : (
            <p className="text-sm text-neutral-500">Nobody has reported this game&rsquo;s sides yet.</p>
          )}
        </section>

        <section className="flex flex-col gap-3" aria-labelledby="game-explore">
          <h2 id="game-explore" className="text-sm uppercase tracking-wide text-neutral-400">
            Explore
          </h2>
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <li>
              <Link
                href={`/games/${page.shortname}/units`}
                className="group flex h-full flex-col gap-1 rounded-md border border-neutral-900 p-4 transition-colors hover:border-neutral-600 active:border-neutral-500"
              >
                <span className="font-medium text-neutral-100 transition-colors group-hover:text-white group-active:text-white">
                  Unit encyclopedia
                </span>
                <span className="text-sm text-neutral-500">
                  Every unit: {gameCountLabel(page)}
                </span>
              </Link>
            </li>
            <li>
              <Link
                href={`/games/${shortname}/tree`}
                className="group flex h-full flex-col gap-1 rounded-md border border-neutral-900 p-4 transition-colors hover:border-neutral-600 active:border-neutral-500"
              >
                <span className="font-medium text-neutral-100 transition-colors group-hover:text-white group-active:text-white">
                  Build tree
                </span>
                <span className="text-sm text-neutral-500">
                  What each faction can reach, from its start units
                </span>
              </Link>
            </li>
            <li>
              <Link
                href={`/gallery?game=${encodeURIComponent(shortname)}`}
                className="group flex h-full flex-col gap-1 rounded-md border border-neutral-900 p-4 transition-colors hover:border-neutral-600 active:border-neutral-500"
              >
                <span className="font-medium text-neutral-100 transition-colors group-hover:text-white group-active:text-white">
                  Community items
                </span>
                <span className="text-sm text-neutral-500">{itemCountLabel(page.item_count)}</span>
              </Link>
            </li>
          </ul>
        </section>

        {/* Claiming a game is rare, so the ask folds down to one quiet line
         *  below the facts (#249) and only opens for whoever wants it. Open,
         *  the instruction lives in a real label where typing cannot take it
         *  away, and the button reads as an action rather than another chip. */}
        {!page.owner_user_id && user ? (
          <details>
            <summary className="w-fit cursor-pointer list-none text-sm text-neutral-400 underline-offset-4 transition-colors hover:text-neutral-200 hover:underline active:text-neutral-200 active:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400">
              Are you this game&rsquo;s author?
            </summary>
            <form action={requestOwnership} className="mt-3 flex max-w-xl flex-col gap-2">
              <input type="hidden" name="shortname" value={shortname} />
              <label htmlFor="ownership-note" className="text-xs uppercase tracking-wide text-neutral-400">
                Say who you are and how you&rsquo;re involved
              </label>
              <textarea
                id="ownership-note"
                name="note"
                rows={4}
                maxLength={2000}
                className="w-full resize-none rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400"
              />
              <button
                type="submit"
                className="self-start rounded-md bg-neutral-100 px-5 py-2.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-white active:bg-neutral-300"
              >
                Request ownership
              </button>
            </form>
          </details>
        ) : null}

        <p className="text-sm text-neutral-500">
          <Link href="/games" className="text-neutral-300 underline-offset-4 hover:underline active:underline">
            Every game the hub knows about
          </Link>
          .
        </p>
      </div>
    </main>
  );
}

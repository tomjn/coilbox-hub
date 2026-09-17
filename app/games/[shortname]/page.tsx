import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { cache } from "react";
import { requestOwnership, setGameVisibility } from "@/app/games/actions";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { games } from "@/components/art/drawings";
import { CommanderPicture } from "@/components/CommanderPicture";
import { GameLogo } from "@/components/GameLogo";
import { ExternalIcon, GamesIcon } from "@/components/icons";
import { RichText } from "@/components/RichText";
import { VisibilityToggleForm } from "@/components/VisibilityToggleForm";
import { staticTierUrl } from "@/lib/assets/cdn";
import { gameArtUrl } from "@/lib/games/art";
import { gameCountLabel, gameTitle, itemCardLabel, saysMoreThanName } from "@/lib/games/labels";
import { gamePageCached, gameSidesCached } from "@/lib/games/cached";
import { editableGame } from "@/lib/games/editor";
import type { GamePageFaction } from "@/lib/games/page";
import type { SideCommander } from "@/lib/games/sides";
import { createClient } from "@/lib/supabase/server";
import { richTextToPlainText } from "@/lib/text/richText";

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
  const description = page.description
    ? richTextToPlainText(page.description)
    : `${page.faction_count} factions and ${page.unit_count} units on the hub.`;

  return {
    title: `${title} - Coilbox Hub`,
    description,
    openGraph: { title, description, type: "article" },
  };
}

/** The card style the games listing uses, so the ways on from this page read
 *  as the same kind of thing as the cards that led here. */
const CARD =
  "group flex h-full rounded-md border border-neutral-800 bg-card transition-colors hover:border-neutral-600 active:border-neutral-500";

/** The controls this page offers beside the title: the owner's two, and the
 *  download where a game names one. A link and a button doing neighbouring
 *  jobs should not look like two different kinds of thing. */
const CONTROL_BUTTON =
  "rounded-md border border-neutral-800 px-3 py-1.5 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-60";

/** A side's picture slot. Every tile in a row gets one when any side has a
 *  picture, so names line up, and the empty slot carries the games icon the way
 *  an empty logo tile does. */
const SLOT = "size-12 shrink-0 rounded";

/**
 * One side of the game, as a tile beside its fellows. The name is always
 * there. The picture is the side's own logo when the hub holds one, and
 * otherwise the unit a player starts that side with, which is what a player of
 * the game recognises it by. The whole tile is a link into the encyclopedia,
 * filtered to that side (#280).
 */
function Faction({
  game,
  faction,
  commander,
  slot,
}: {
  game: string;
  faction: GamePageFaction;
  commander: SideCommander | undefined;
  slot: boolean;
}) {
  let picture = null;
  if (faction.logo_path) {
    picture = (
      // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image, see next.config.ts
      <img
        src={staticTierUrl(faction.logo_path)}
        alt=""
        width={48}
        height={48}
        loading="lazy"
        decoding="async"
        className={`${SLOT} bg-black object-contain`}
      />
    );
  } else if (commander) {
    picture = <CommanderPicture commander={commander} className={`${SLOT} bg-black`} />;
  } else if (slot) {
    picture = (
      <span aria-hidden className={`${SLOT} flex items-center justify-center bg-neutral-900`}>
        <GamesIcon className="w-5 text-neutral-600" />
      </span>
    );
  }

  return (
    <li>
      <Link
        href={`/games/${game}/units?faction=${encodeURIComponent(faction.key)}`}
        className={`${CARD} items-center gap-3 ${picture ? "p-2 pr-4" : "px-4 py-3"}`}
      >
        {picture}
        <span className="min-w-0 break-words font-medium text-neutral-100 transition-colors group-hover:text-white group-active:text-white">
          {faction.name}
        </span>
      </Link>
    </li>
  );
}

/** One way on from the game's page: a name and a line saying what is there. */
function Onward({ href, name, detail }: { href: string; name: string; detail: string }) {
  return (
    <li>
      <Link href={href} className={`${CARD} flex-col gap-1 p-4`}>
        <span className="font-medium text-neutral-100 transition-colors group-hover:text-white group-active:text-white">
          {name}
        </span>
        <span className="text-sm text-pretty text-neutral-400">{detail}</span>
      </Link>
    </li>
  );
}

export default async function Game({ params }: { params: Promise<{ shortname: string }> }) {
  const { shortname } = await params;
  const page = await load(shortname);
  if (!page) notFound();

  const title = gameTitle(page);
  // A picture still in the staging bucket comes from the hub's own route, and
  // an unrecognised staged tier is not drawn (#345).
  const banner = gameArtUrl(page.shortname, "banner", {
    path: page.banner_path,
    hash: page.banner_hash,
    staged_tier: page.banner_staged_tier,
  });
  const logo = gameArtUrl(page.shortname, "logo", {
    path: page.logo_path,
    hash: page.logo_hash,
    staged_tier: page.logo_staged_tier,
  });

  // The session decides what the visitor sees: an unowned game asks for
  // somebody to take it, the owner or a moderator (#350) gets the pen and the
  // hide switch, and anybody else sees neither, because who owns a game is not
  // a fact a visitor needs.
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  const mayEdit = user !== null && (await editableGame(supabase, user.id, shortname)) !== null;

  const commanders = (await gameSidesCached([page.shortname])).get(page.shortname)?.commanders;
  const factionSlots = page.factions.some(
    (faction) => faction.logo_path !== null || commanders?.has(faction.key),
  );
  const describes =
    page.description !== null && saysMoreThanName(page, richTextToPlainText(page.description));

  return (
    <main className="relative flex-1">
      {banner ? (
        // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image, see next.config.ts
        <img
          src={banner}
          alt=""
          decoding="async"
          className="h-40 w-full object-cover sm:h-56"
        />
      ) : null}
      <ArtBackdrop drawing={games} strength={banner ? 0 : BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <div className="flex flex-col gap-3">
          <nav className="text-sm text-neutral-400" aria-label="Breadcrumb">
            <Link href="/games" className="underline-offset-4 hover:text-neutral-200 hover:underline active:underline">
              Games
            </Link>
            <span aria-hidden> / </span>
            <span className="text-neutral-200">{title}</span>
          </nav>
          <div className="flex items-center gap-4">
            <GameLogo src={logo} alt={`${title} logo`} />
            <h1 className="min-w-0 break-words text-3xl font-semibold tracking-tight text-balance">
              {title}
            </h1>
          </div>
          {describes && page.description ? (
            <RichText text={page.description} className="max-w-prose text-neutral-300" />
          ) : null}
          {page.release ? (
            <p className="text-sm text-neutral-400">Game version {page.release}</p>
          ) : null}
          {mayEdit ? (
            <div className="flex flex-wrap items-center gap-3 pt-1">
              <Link href={`/games/${shortname}/edit`} className={CONTROL_BUTTON}>
                Edit this game
              </Link>
              <VisibilityToggleForm
                action={setGameVisibility}
                fields={{ shortname, hidden: "true", onSuccess: "edit" }}
                label="Hide this game"
                pendingLabel="Hiding…"
                // The default is a flex column, which stretches its button
                // across the whole page. Only this call site needs fixing: the
                // edit page and the moderation queue pass their own layout.
                formClassName="flex flex-col items-start gap-1.5"
                buttonClassName={CONTROL_BUTTON}
              />
            </div>
          ) : null}
        </div>

        <section className="flex flex-col gap-3" aria-labelledby="game-factions">
          <h2 id="game-factions" className="text-sm uppercase tracking-wide text-neutral-400">
            Factions
          </h2>
          {page.factions.length > 0 ? (
            <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {page.factions.map((faction) => (
                <Faction
                  key={faction.key}
                  game={page.shortname}
                  faction={faction}
                  commander={commanders?.get(faction.key)}
                  slot={factionSlots}
                />
              ))}
            </ul>
          ) : (
            <p className="text-sm text-neutral-400">Nobody has reported this game&rsquo;s sides yet.</p>
          )}
        </section>

        <section className="flex flex-col gap-3" aria-labelledby="game-explore">
          <h2 id="game-explore" className="text-sm uppercase tracking-wide text-neutral-400">
            Explore
          </h2>
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Onward
              href={`/games/${page.shortname}/units`}
              name="Unit encyclopedia"
              detail={gameCountLabel(page)}
            />
            <Onward
              href={`/games/${shortname}/tree`}
              name="Build tree"
              detail="What each faction can reach from its start units"
            />
            <Onward
              href={`/gallery?game=${encodeURIComponent(shortname)}`}
              name="Community items"
              detail={itemCardLabel(page.item_count)}
            />
          </ul>
        </section>

        {/* Last, because they leave the hub. Each carries the outbound arrow so
         *  it does not read as one more way into this game's pages. */}
        {page.links.length > 0 ? (
          <section className="flex flex-col gap-3" aria-labelledby="game-links">
            <h2 id="game-links" className="text-sm uppercase tracking-wide text-neutral-400">
              Links
            </h2>
            <ul className="flex flex-wrap gap-2">
              {page.links.map((link) => (
                <li key={link.url}>
                  <a
                    href={link.url}
                    rel="noopener noreferrer"
                    className="flex items-center gap-2 rounded-md border border-neutral-800 px-3 py-1.5 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
                  >
                    {link.label}
                    <ExternalIcon className="w-3.5 text-neutral-400" />
                  </a>
                </li>
              ))}
            </ul>
          </section>
        ) : null}

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
                className="w-full resize-none rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400"
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

        <p className="text-sm text-neutral-400">
          <Link href="/games" className="text-neutral-300 underline-offset-4 hover:underline active:underline">
            Every game the hub knows about
          </Link>
          .
        </p>
      </div>
    </main>
  );
}

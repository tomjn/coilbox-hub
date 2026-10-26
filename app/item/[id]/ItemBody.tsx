import { buttonClass } from "@/components/Button";
import Link from "next/link";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { DateTime } from "@/components/DateTime";
import { ImportLink } from "@/components/ImportLink";
import { ItemPreview, type UnitNameLink } from "@/components/ItemPreview";
import { KindIcon } from "@/components/KindIcon";
import { LobbyCommands } from "@/components/LobbyCommands";
import { MapMinimap } from "@/components/MapMinimap";
import { ReportButton } from "@/components/ReportButton";
import { type PackMap, SetupPackContents } from "@/components/SetupPackContents";
import { VisibilityToggleForm } from "@/components/VisibilityToggleForm";
import { itemArt } from "@/lib/gallery/itemArt";
import type { ItemDetail } from "@/lib/gallery/itemCached";
import type { ItemPictures } from "@/lib/gallery/itemPictures";
import { itemLabel } from "@/lib/gallery/label";
import { startPosNote } from "@/lib/gallery/presetPreview";
import { setupPackMaps } from "@/lib/gallery/setupPackPreview";
import {
  gameFactionsCached,
  unitNameLabelsCached,
  unitRolesCached,
  type UnitNameLabel,
} from "@/lib/games/cached";
import type { BuildingRoles } from "@/lib/blueprint/role";
import { lobbyCommands } from "@/lib/workshop/lobbyCommands";
import { presetCommands } from "@/lib/workshop/presetCommands";
import { importOrigin } from "@/lib/site";
import { setItemFeatured } from "../actions";

function Fact({ term, children }: { term: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-xs uppercase tracking-wide text-neutral-400">
        {term}
      </dt>
      <dd className="text-sm text-neutral-100">{children}</dd>
    </div>
  );
}

/**
 * What an item page draws, whoever it is drawn for. `/item/[id]` draws it from
 * the cached public row for everybody, and `/item/[id]/withdrawn` from the
 * visitor's own read for the author and moderators. `controls` is what only
 * those people get, which each page decides in its own way.
 */
export async function ItemBody({
  item,
  pictures,
  controls,
}: {
  item: ItemDetail;
  pictures: ItemPictures;
  controls: React.ReactNode;
}) {
  const origin = importOrigin();
  const shareUrl = `${origin}/i/${item.id}`;
  const { drawing, strength } = itemArt(item.kind, item.mode);

  // What the catalog calls each unit in a blueprint or a mod-project, so a
  // roster or a changelog reads as "Cold Fusion Power Plant" and links to its
  // encyclopedia page rather than spelling the def key. An item without a
  // game, or in a game the hub holds no catalog for, gets an empty map and
  // keeps the raw keys.
  //
  // What each unit does colours a layout's buildings, on a blueprint and on
  // the layouts a setup pack carries. Neither read waits on the other.
  const [names, roles]: [ReadonlyMap<string, UnitNameLabel>, BuildingRoles] =
    await Promise.all([
      (item.kind === "blueprint" || item.kind === "mod-project") && item.game_key
        ? unitNameLabelsCached(item.game_key)
        : new Map(),
      (item.kind === "blueprint" || item.kind === "setup-pack") && item.game_key
        ? unitRolesCached(item.game_key)
        : {},
    ]);
  const unitNames: ReadonlyMap<string, UnitNameLink> = new Map(
    [...names].map(([def, entry]) => [
      def,
      {
        label: entry.label,
        href: `/games/${item.game_key}/units/${entry.name}`,
      },
    ]),
  );

  // The two kinds somebody can use without coilbox (issue #418). A project's
  // edits compile to tweak slots, and a preset's setup translates to the
  // commands that rebuild it in a lobby. Nothing when the payload is not one
  // coilbox wrote, and the page then offers the import alone.
  const project = item.kind === "mod-project";
  const payload = (item.container as { payload?: unknown } | null)?.payload;
  // A preset names its factions in the game's own words, and a host matches
  // one by its first word. The game's full list is what says whether that word
  // reaches one faction or two, so it is read here and handed over.
  const factions =
    item.kind === "preset" && item.game_key ? await gameFactionsCached(item.game_key) : [];
  const commands = project
    ? lobbyCommands(payload)
    : item.kind === "preset"
      ? presetCommands(payload, factions)
      : null;

  // Every map this page names: the one on the row, and a setup pack's own list
  // (issue #176).
  const packMapNames = setupPackMaps(item.container);
  // The pictures came with the row, from whichever of the two readings `load`
  // made: the row's map, a pack's maps, and a buildpic for every distinct unit
  // in a blueprint (issue #109), all in one lookup.
  const packMaps: PackMap[] = packMapNames.flatMap((name) => {
    // Present for every name asked for, since a map with nothing stored
    // resolves to the placeholder rather than to nothing. The catalog row is
    // the other way round: most maps have none, and a card without one is the
    // card the pack has always shown.
    const picture = pictures.packMaps.get(name);
    return picture ? [{ name, picture, catalog: pictures.catalog.get(name) ?? null }] : [];
  });
  // An item that names a map always gets the slot. Whether the hub's own
  // minimap or a drawing fills it is `MapMinimap`'s to decide, and one of the
  // two always can. A setup pack is the exception below: it draws its own maps
  // under a heading, one or twenty.
  const minimap = pictures.map ? (
    <MapMinimap
      name={item.map_name ?? ""}
      picture={pictures.map}
      note={startPosNote(item.kind, item.container)}
      catalog={item.map_name ? (pictures.catalog.get(item.map_name) ?? null) : null}
    />
  ) : null;

  return (
    <main id="main-content" tabIndex={-1} className="relative flex-1">
      <ArtBackdrop drawing={drawing} strength={strength} />
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-8 px-6 py-12">
        <div className="flex flex-col gap-3">
          <Link
            href={`/gallery?kind=${item.kind}`}
            className="flex items-center gap-1.5 self-start rounded border border-neutral-800 px-2 py-1 text-xs text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
          >
            <KindIcon kind={item.kind} mode={item.mode} className="w-3.5" />
            {itemLabel(item.kind, item.mode)}
          </Link>
          <h1 className="break-words text-3xl font-semibold tracking-tight">
            {item.title}
          </h1>
          {item.description ? (
            <p className="whitespace-pre-wrap text-neutral-400">
              {item.description}
            </p>
          ) : null}
          {project ? (
            // What a project changes comes before how to get it, and that list
            // can run to two hundred rows. These are for the reader who has
            // already decided.
            <p className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
              <a
                href="#coilbox"
                className="py-1 text-neutral-300 underline underline-offset-4 hover:text-white active:text-white"
              >
                Open it in Coilbox
              </a>
              {commands && commands.lines.length > 0 ? (
                <a
                  href="#lobby"
                  className="py-1 text-neutral-300 underline underline-offset-4 hover:text-white active:text-white"
                >
                  Autohost commands
                </a>
              ) : null}
            </p>
          ) : null}
        </div>

        {item.kind === "setup-pack" ? (
          <SetupPackContents
            container={item.container}
            maps={packMaps}
            units={pictures.units}
            roles={roles}
            gameKey={item.game_key}
          />
        ) : minimap ? (
          // Where and who, side by side: the two halves of what a preset is.
          // `empty:hidden` because a kind can render no preview at all, and an
          // empty flex item would still take the row's gap.
          <div className="flex flex-col gap-6 sm:flex-row sm:items-start">
            <div className="w-full shrink-0 sm:w-80">{minimap}</div>
            <div className="min-w-0 flex-1 empty:hidden">
              <ItemPreview
                kind={item.kind}
                container={item.container}
                units={pictures.units}
                names={unitNames}
                roles={roles}
              />
            </div>
          </div>
        ) : (
          <ItemPreview
            kind={item.kind}
            container={item.container}
            units={pictures.units}
            names={unitNames}
            roles={roles}
          />
        )}

        {/* The two ways to get it, Coilbox first: it applies every edit, and the
            commands only what a lobby can carry. The row breaks out of the
            reading column to the width of the page, and a card that would
            drop below 28rem goes under the other one. Each is as tall as what
            it holds, not as tall as its neighbour. */}
        <div
          className={
            commands
              ? "relative left-1/2 grid w-[calc(100vw-3rem)] -translate-x-1/2 grid-cols-[repeat(auto-fit,minmax(min(100%,28rem),1fr))] items-start gap-8"
              : undefined
          }
        >
          <div
            id="coilbox"
            className="flex min-w-0 flex-col gap-4 rounded-md border border-neutral-800 bg-card p-5"
          >
            {commands ? (
              <div className="flex flex-col gap-1.5">
                <h2 className="text-lg font-semibold tracking-tight">Open it in Coilbox</h2>
                <p className="text-sm text-neutral-400">
                  {project
                    ? "Coilbox applies every edit, to any game it runs, and lets you change them."
                    : "Coilbox sets the whole thing up, colours and factions included, and lets you change it."}
                </p>
              </div>
            ) : null}
            <ImportLink shareUrl={shareUrl} variant="solid" />
            <div className="flex flex-col gap-1.5">
              <span className="text-xs text-neutral-400">
                Or share this link. It opens in Coilbox and needs no account.
              </span>
              <code className="break-all rounded border border-neutral-800 bg-black px-3 py-2 text-xs text-neutral-400">
                {shareUrl}
              </code>
            </div>
          </div>
          {commands ? <LobbyCommands commands={commands} /> : null}
        </div>

        {controls}

        <dl className="grid grid-cols-2 gap-6 border-t border-neutral-900 pt-6 sm:grid-cols-4">
          <Fact term="Published by">
            <Link
              href={`/gallery?author=${encodeURIComponent(item.author_name)}`}
              className="hover:text-white active:text-white"
            >
              {item.author_name}
            </Link>
          </Fact>
          <Fact term="Published">
            <DateTime value={item.created_at} />
          </Fact>
          {item.import_count > 0 ? (
            // Zero is not shown at all (issue #51): most items sit at zero
            // for a long time, since only coilbox's own release onward can
            // ever send this ping, and a row of "0" reads as unwanted rather
            // than as "not counted yet".
            <Fact term="Imported via hub link">{item.import_count}</Fact>
          ) : null}
          {item.game_name ? (
            // "Made with" on a project, because the build named here is what
            // the author had and not what a player needs (issue #419).
            <Fact term={project ? "Made with" : "Game"}>
              {item.game_key ? (
                <Link
                  href={`/gallery?game=${encodeURIComponent(item.game_key)}`}
                  className="hover:text-white active:text-white"
                >
                  {item.game_name}
                </Link>
              ) : (
                // No key to filter by (issue #50): this names the exact build
                // rather than the stable game, so it is shown but not offered
                // as a link that would filter to nothing else.
                item.game_name
              )}
            </Fact>
          ) : null}
          {item.map_name ? (
            <Fact term="Map">
              <Link
                href={`/gallery?map=${encodeURIComponent(item.map_name)}`}
                className="hover:text-white active:text-white"
              >
                {item.map_name}
              </Link>
            </Fact>
          ) : null}
        </dl>

        {item.tags.length > 0 ? (
          <ul className="flex flex-wrap gap-1.5">
            {item.tags.map((tag) => (
              <li key={tag}>
                <Link
                  href={`/gallery?tag=${encodeURIComponent(tag)}`}
                  className="inline-block rounded bg-neutral-900 px-2 py-1 text-xs text-neutral-400 transition-colors hover:text-neutral-200 active:text-neutral-200"
                >
                  {tag}
                </Link>
              </li>
            ))}
          </ul>
        ) : null}
        <ReportButton itemId={item.id} />
      </div>
    </main>
  );
}

/** The author's note and the way to their edit page. */
export function ItemOwnerNote({ id, withdrawn }: { id: string; withdrawn: boolean }) {
  return (
          <div className="flex items-center justify-between rounded-md border border-neutral-800 bg-card px-5 py-3 text-sm">
            <span className="text-neutral-400">
              {withdrawn ? "You have withdrawn this." : "This is yours."}
            </span>
            <Link
              href={`/item/${id}/edit`}
              className="text-neutral-300 underline-offset-4 hover:underline active:underline"
            >
              Edit or withdraw
            </Link>
          </div>
  );
}

/** A moderator's feature switch (#395). `public.set_item_featured` asks again,
 *  as the session, before it writes anything. */
export function ItemFeatureControl({ item }: { item: Pick<ItemDetail, "id" | "featured_at"> }) {
  return (
          <div className="flex items-center justify-between gap-3 rounded-md border border-neutral-800 bg-card px-5 py-3 text-sm">
            <span className="text-neutral-400">
              {item.featured_at
                ? "Featured, so the gallery lists this first."
                : "Not featured."}
            </span>
            <VisibilityToggleForm
              action={setItemFeatured}
              fields={{
                id: item.id,
                featured: item.featured_at ? "false" : "true",
              }}
              label={item.featured_at ? "Unfeature" : "Feature"}
              pendingLabel={item.featured_at ? "Removing…" : "Featuring…"}
              formClassName="flex flex-wrap items-center justify-end gap-2"
              buttonClassName={buttonClass("ghost", { size: "md" })}
            />
          </div>
  );
}

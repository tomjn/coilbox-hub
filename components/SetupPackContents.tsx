/**
 * What a setup pack installs, under a heading per sort of thing (issue #176).
 *
 * A pack was three boxed columns, games, engine and maps, whatever it held. A
 * pack of four maps therefore spent two thirds of the panel saying "None" and
 * "Whatever you have", and squeezed its actual contents into the last third as
 * a run-on line of names. So each sort of thing gets a heading and the width of
 * the page, and a sort the pack says nothing about gets neither.
 *
 * The maps are shown rather than named. `components/MapMinimap.tsx` draws each
 * one the way the item page draws a preset's map, and the caption carries what
 * BAR's list says about it (`lib/gallery/mapFacts.ts`). Nothing here is drawn
 * from the payload alone, which is why this is not part of
 * `components/ItemPreview.tsx`: the pictures are looked up by the page and
 * handed down, and that component's whole premise is that it fetches nothing.
 */

import { MapMinimap } from "@/components/MapMinimap";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import type { BarMap } from "@/lib/bar/maps";
import { mapFactsLabel } from "@/lib/gallery/mapFacts";
import { mapOverlay } from "@/lib/gallery/mapOverlay";
import {
  setupPackEngine,
  setupPackGameNames,
} from "@/lib/gallery/setupPackPreview";

/** One map a pack installs, with everything needed to draw it: the name the
 *  pack lists, BAR's entry for it where it has one, and the hub's picture or
 *  the placeholder standing in for it. Assembled by `app/item/[id]/page.tsx`. */
export interface PackMap {
  name: string;
  bar: BarMap | null;
  picture: ResolvedAsset;
}

function Section({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section className="flex flex-col gap-3">
      <h2 className="text-xs uppercase tracking-wide text-neutral-400">
        {title}
      </h2>
      {children}
    </section>
  );
}

export function SetupPackContents({
  container,
  maps,
}: {
  container: unknown;
  /** The pack's maps in the order it lists them, from `packMapCards`. */
  maps: PackMap[];
}) {
  const payload = (container as { payload?: unknown } | null)?.payload;
  if (typeof payload !== "object" || payload === null) return null;
  const record = payload as Record<string, unknown>;

  const games = setupPackGameNames(record);
  const engine = setupPackEngine(record);
  // A pack that installs nothing this page can name renders nothing at all,
  // the way every other kind with no preview does. An empty frame reads as
  // broken where an absence reads as a pack with nothing in it.
  if (games.length === 0 && !engine && maps.length === 0) return null;

  return (
    <div className="flex flex-col gap-8">
      {games.length > 0 ? (
        <Section title={games.length === 1 ? "Game" : "Games"}>
          <ul className="flex flex-col gap-1 text-sm text-neutral-100">
            {games.map((game) => (
              <li key={game}>{game}</li>
            ))}
          </ul>
        </Section>
      ) : null}

      {engine ? (
        <Section title="Engine">
          <p className="text-sm text-neutral-100">{engine}</p>
        </Section>
      ) : null}

      {maps.length > 0 ? (
        <Section title={maps.length === 1 ? "Map" : "Maps"}>
          <ul className="grid gap-6 sm:grid-cols-2 md:grid-cols-3">
            {maps.map((map) => (
              // A row of maps is a row of different shapes, so the cards are
              // as tall as the row and the names line up along the bottom of
              // it rather than wherever each picture happens to end.
              <li key={map.name} className="flex">
                <MapMinimap
                  className="h-full w-full"
                  map={map.bar}
                  // BAR's own spelling where it lists the map, since that is
                  // the name a player sees in a lobby. The pack's own name
                  // otherwise, which is all anything knows it by.
                  name={map.bar?.displayName ?? map.name}
                  picture={map.picture}
                  detail={mapFactsLabel(map.bar)}
                  {...mapOverlay("setup-pack", container, map.bar)}
                />
              </li>
            ))}
          </ul>
        </Section>
      ) : null}
    </div>
  );
}

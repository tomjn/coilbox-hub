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
 * one the way the item page draws a preset's map. Nothing here is drawn from
 * the payload alone, which is why this is not part of
 * `components/ItemPreview.tsx`: the pictures are looked up by the page and
 * handed down, and that component's whole premise is that it fetches nothing.
 */

import { MapMinimap, type MinimapFacts } from "@/components/MapMinimap";
import { BlueprintLayoutArt } from "@/components/ItemPreview";
import type { ResolvedAsset, ServedAsset } from "@/lib/assets/resolve";
import type { BuildingRoles } from "@/lib/blueprint/role";
import {
  setupPackBlueprints,
  setupPackEngine,
  setupPackGameNames,
} from "@/lib/gallery/setupPackPreview";

/** One map a pack installs, with everything needed to draw it: the name the
 *  pack lists, the hub's picture or the placeholder standing in for it, and what
 *  the catalog holds for it where the hub holds anything it may publish.
 *  Assembled by `app/item/[id]/page.tsx`. */
export interface PackMap {
  name: string;
  picture: ResolvedAsset;
  catalog: MinimapFacts | null;
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

const NO_UNITS: ReadonlyMap<string, ServedAsset> = new Map();
const NO_ROLES: BuildingRoles = {};

/** The size {@link BlueprintLayoutArt} fits a layout's sheet to, matching the
 *  `aspect-[4/3]` class it is drawn with below. */
const LAYOUT_BOX = { width: 320, height: 240 };

export function SetupPackContents({
  container,
  maps,
  units = NO_UNITS,
  roles = NO_ROLES,
  gameKey = null,
}: {
  container: unknown;
  /** The pack's maps in the order it lists them, from `packMapCards`. */
  maps: PackMap[];
  /** The unit pictures the hub holds, from `lib/gallery/itemPictures.ts`. A
   *  layout is drawn with them only when it was drawn for the pack's game. */
  units?: ReadonlyMap<string, ServedAsset>;
  /** What each of the pack's game's units does, from `unitRolesCached`. Used
   *  under the same rule as `units`. */
  roles?: BuildingRoles;
  gameKey?: string | null;
}) {
  const payload = (container as { payload?: unknown } | null)?.payload;
  if (typeof payload !== "object" || payload === null) return null;
  const record = payload as Record<string, unknown>;

  const games = setupPackGameNames(record);
  const engine = setupPackEngine(record);
  const blueprints = setupPackBlueprints(record);
  // A pack that installs nothing this page can name renders nothing at all,
  // the way every other kind with no preview does. An empty frame reads as
  // broken where an absence reads as a pack with nothing in it.
  if (
    games.length === 0 &&
    !engine &&
    maps.length === 0 &&
    blueprints.length === 0
  ) {
    return null;
  }

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
                  name={map.name}
                  picture={map.picture}
                  catalog={map.catalog}
                  // A pack says nothing about how any of its maps get played.
                  note={null}
                />
              </li>
            ))}
          </ul>
        </Section>
      ) : null}

      {blueprints.length > 0 ? (
        <Section title={blueprints.length === 1 ? "Blueprint" : "Blueprints"}>
          <ul className="grid gap-6 sm:grid-cols-2">
            {blueprints.map((layout, i) => {
              const buildings = layout.shape.squares.length;
              const steps = layout.shape.terrain.length;
              const ours = layout.game === null || layout.game === gameKey;
              return (
                // A pack can carry two layouts of one name, so the name alone
                // is not a key.
                <li key={i} className="flex flex-col gap-2">
                  <BlueprintLayoutArt
                    shape={layout.shape}
                    box={LAYOUT_BOX}
                    className="aspect-[4/3] w-full"
                    units={ours ? units : NO_UNITS}
                    roles={ours ? roles : NO_ROLES}
                  />
                  <p className="break-words text-sm text-neutral-100">
                    {layout.name.trim() || "Untitled layout"}
                  </p>
                  <p className="text-xs text-neutral-400">
                    {[
                      ...(buildings > 0
                        ? [`${buildings} ${buildings === 1 ? "building" : "buildings"}`]
                        : []),
                      ...(steps > 0
                        ? [`${steps} terrain ${steps === 1 ? "step" : "steps"}`]
                        : []),
                    ].join(", ")}
                    {layout.shape.ordered && buildings > 0 ? ", in build order" : ""}
                  </p>
                </li>
              );
            })}
          </ul>
        </Section>
      ) : null}
    </div>
  );
}

import Link from "next/link";
import { AssetPlaceholder } from "@/components/AssetPlaceholder";
import type { MapFacts } from "@/lib/api/mapLookup";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import { mapSizeLabel, playerCountLabel } from "@/lib/maps/labels";

/**
 * The map an item is played on.
 *
 * The hub's own stored minimap (issue #109) where it holds one, and a
 * placeholder otherwise, so this component always has something to show and the
 * page never has a hole in it where a picture failed to load.
 *
 * There used to be a third answer ahead of both: BAR's own thumbnail, fetched
 * per render off its validated map list, with the start boxes and spawn points
 * drawn over it. #180 took it out, and the hub now shows only pictures it holds
 * itself, so no page waits on somebody else's server in order to render.
 *
 * ## The facts that went with that thumbnail are back, off the hub's own catalog
 *
 * Where the hub holds a map in `public.map` and the licence gate lets it publish
 * the map, `lib/gallery/itemPictures.ts` passes the row down as {@link catalog}
 * and this captions the picture with the map's size and player count, and links
 * to `/map/[slug]` (issue #191).
 *
 * Where it holds no such row this renders exactly as it did before, which is the
 * state the component was built around and is what most items will show for a
 * while. The catalog fills up as clients submit, and neither a map nobody has
 * submitted nor a map the hub has agreed not to publish is a fault.
 *
 * The words are `lib/maps/labels.ts`, which is where the map's own page and the
 * catalog listing get theirs, so one map is not described one way here and
 * another way one click later.
 *
 * Nothing shown here can be a substitute for something else. `resolveAsset`
 * stands a buildpic in for a missing render angle and for nothing else, and a
 * minimap has no stand-in, so the caption never has to qualify what it is
 * captioning. Whoever adds a caller that asks for a render must read
 * `served.variant` off the answer before labelling it.
 */

/** What the caption and the link are made of. The whole row is not needed and
 *  the shape is `MapFacts`'s own rather than a fourth spelling of it. */
export type MinimapFacts = Pick<
  MapFacts,
  "slug" | "width_elmos" | "height_elmos" | "points"
>;

/** The picture links to the map's own page when the hub has one to link to, and
 *  is the bare picture when it has not. */
function ToMap({ slug, children }: { slug: string | null; children: React.ReactNode }) {
  if (!slug) return <>{children}</>;

  return (
    <Link href={`/map/${slug}`} className="block">
      {children}
    </Link>
  );
}

export function MapMinimap({
  name,
  picture,
  note,
  catalog,
  className,
}: {
  /** What to call it, which is the name the item itself carries. */
  name: string;
  /** The hub's own picture of this map, or the placeholder standing in for it.
   * From `lib/gallery/itemPictures.ts`. */
  picture: ResolvedAsset;
  /** How start positions get chosen, when the payload says. */
  note: string | null;
  /** What the catalog holds for this map, or null when the hub holds nothing it
   * may publish. Also from `lib/gallery/itemPictures.ts`. Passed even when it is
   * null, the same as {@link note}, so a caller that has never thought about it
   * does not quietly drop the link. */
  catalog: MinimapFacts | null;
  /** Lands on the figure. `h-full` is what a pack's grid passes, so the
   * captions in a row line up along the bottom however tall each map is. */
  className?: string;
}) {
  const slug = catalog?.slug ?? null;
  // The size, and the player count when the map declares start positions. Both
  // absent without a catalog row, which is what keeps that case rendering the
  // way it always did.
  const facts = catalog
    ? [
        mapSizeLabel(catalog.width_elmos, catalog.height_elmos),
        playerCountLabel(catalog.points.start.length),
      ]
        .filter(Boolean)
        .join(", ")
    : null;

  // Nothing to show, so the drawing is the answer. The note still applies: how
  // start positions get chosen is a fact about the item rather than about the
  // picture. The drawing carries the map's name itself, so the caption does not
  // repeat it.
  if (picture.from === "placeholder") {
    return (
      <figure className={`flex flex-col gap-2${className ? ` ${className}` : ""}`}>
        <ToMap slug={slug}>
          <AssetPlaceholder of={picture} />
        </ToMap>
        {facts || note ? (
          <figcaption className="mt-auto flex flex-col gap-0.5 text-xs text-neutral-400">
            {facts ? <span>{facts}</span> : null}
            {note ? <span>{note}</span> : null}
          </figcaption>
        ) : null}
      </figure>
    );
  }

  // The picture's own proportions. A stored minimap is drawn here as the archive
  // encoded it, unlike the map's own page, which draws the frame at the
  // catalog's size because the markers over it are placed in the map's own
  // coordinates and only land if the frame is the map's extent.
  const shape = { width: picture.width, height: picture.height };

  return (
    <figure className={`flex flex-col gap-2${className ? ` ${className}` : ""}`}>
      <ToMap slug={slug}>
        <div
          className="relative overflow-hidden rounded-md border border-neutral-800 bg-black"
          style={{ aspectRatio: `${shape.width} / ${shape.height}` }}
        >
          {/* A plain img and not next/image. The hub encodes its own assets at
              the size they are shown, and the Hobby allowance is around 5,000
              transformations a month metered on unique source images, which is
              one per map in existence. */}
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={picture.url}
            alt={`Minimap of ${name}`}
            className="absolute inset-0 size-full object-cover"
          />
        </div>
      </ToMap>
      <figcaption className="mt-auto flex flex-col gap-0.5 text-xs text-neutral-400">
        <span className="text-neutral-300">
          {slug ? (
            <Link href={`/map/${slug}`} className="hover:underline">
              {name}
            </Link>
          ) : (
            name
          )}
        </span>
        {facts ? <span>{facts}</span> : null}
        {note ? <span>{note}</span> : null}
      </figcaption>
    </figure>
  );
}

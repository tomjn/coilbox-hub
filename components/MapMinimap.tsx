import Image from "next/image";
import { AssetPlaceholder } from "@/components/AssetPlaceholder";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import type { BarMap } from "@/lib/bar/maps";
import { previewAtSize } from "@/lib/bar/previewUrl";
import type { StartLayout } from "@/lib/bar/startLayout";

/**
 * The map an item is played on.
 *
 * BAR publishes a thumbnail and the start geometry for every map it certifies,
 * keyed by the same spring name coilbox writes into a preset, so for a listed
 * map that is the real thing rather than a drawing standing in for one.
 *
 * A map BAR does not certify used to be a dead end: no thumbnail, no picture,
 * nothing at all beside the composition. Issue #109 gives it two more answers.
 * The hub's own stored minimap comes next, and a placeholder drawn from the
 * map's size comes last, so this component always has something to show and the
 * page never has a hole in it where a picture failed to load.
 *
 * BAR's copy is preferred over the hub's on cost rather than quality. BAR serves
 * from its own proxy and the hub's staging tier spends Blob data transfer out of
 * 10 GB a month, so the free picture goes first where there are two.
 *
 * The overlay is honest about what it knows. Boxes are BAR's layout for a
 * battle of this shape, drawn in the participants' own colours so they read
 * against the composition beside them, but which team takes which box is
 * settled at launch and the note underneath says so. Spawn dots only appear
 * where BAR described a layout for exactly this many players a side. None of it
 * exists for a map BAR does not list, since the geometry comes off the same
 * entry as the thumbnail.
 *
 * Nothing shown here can be a substitute for something else. `resolveAsset`
 * stands a buildpic in for a missing render angle and for nothing else, and a
 * minimap has no stand-in, so the caption never has to qualify what it is
 * captioning. Whoever adds a caller that asks for a render must read
 * `served.variant` off the answer before labelling it.
 */

/** Wide enough for the largest slot the page gives it on a retina screen, and
 * a fifth the weight of the 1024 the list hands out. */
const PREVIEW_SIZE = 512;

export function MapMinimap({
  map,
  name,
  picture,
  layout,
  allyColors,
  note,
}: {
  /** The map as BAR lists it, or null for one it does not. */
  map: BarMap | null;
  /** What to call it: BAR's display name where there is one, and the item's own
   * map name otherwise. */
  name: string;
  /** The hub's own picture of this map, or the placeholder standing in for it.
   * From `lib/gallery/itemPictures.ts`. */
  picture: ResolvedAsset;
  layout: StartLayout;
  /** Ally team index to CSS colour, from the item's own participants. Empty
   * for anything with no composition, such as a setup pack. */
  allyColors: string[];
  /** How start positions get chosen, when the payload says. */
  note: string | null;
}) {
  const barPreview = map?.images?.preview;

  // Nothing to show, so the drawing is the answer. The note still applies: how
  // start positions get chosen is a fact about the item rather than about the
  // picture. Everything else in the caption describes marks on an image there
  // is no image to put.
  if (!barPreview && picture.from === "placeholder") {
    return (
      <figure className="flex flex-col gap-2">
        <AssetPlaceholder of={picture} />
        {note ? (
          <figcaption className="text-xs text-neutral-400">{note}</figcaption>
        ) : null}
      </figure>
    );
  }

  const stored = picture.from === "placeholder" ? null : picture;
  const colorOf = (index: number) => allyColors[index] ?? "#e5e5e5";
  const { boxes, dots } = layout;
  // BAR's own size where it has one, and the picture's own proportions
  // otherwise, which is the only thing that says what shape a map the hub was
  // handed a picture of actually is.
  const shape = map?.mapWidth
    ? { width: map.mapWidth, height: map.mapHeight ?? map.mapWidth }
    : { width: stored?.width ?? 1, height: stored?.height ?? 1 };

  return (
    <figure className="flex flex-col gap-2">
      <div
        className="relative overflow-hidden rounded-md border border-neutral-800 bg-black"
        style={{ aspectRatio: `${shape.width} / ${shape.height}` }}
      >
        {barPreview ? (
          <Image
            src={previewAtSize(barPreview, PREVIEW_SIZE)}
            alt={`Minimap of ${name}`}
            fill
            sizes="(min-width: 640px) 20rem, 100vw"
            // BAR's own image proxy has already fitted and compressed this, so
            // running it through a second optimiser would cost money to make it
            // no smaller.
            unoptimized
          />
        ) : (
          // A plain img and not next/image, for the same reason `unoptimized`
          // is on the one above and a stronger one besides. The hub encodes its
          // own assets at the size they are shown, and the Hobby allowance is
          // around 5,000 transformations a month metered on unique source
          // images, which is one per map in existence.
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={stored?.url}
            alt={`Minimap of ${name}`}
            className="absolute inset-0 size-full object-cover"
          />
        )}
        {boxes.map((box, index) => (
          <div
            key={index}
            className="absolute border-2"
            style={{
              left: `${box.left * 100}%`,
              top: `${box.top * 100}%`,
              width: `${box.width * 100}%`,
              height: `${box.height * 100}%`,
              borderColor: colorOf(index),
              background: `${colorOf(index)}26`,
              // A dark hairline outside the coloured border, so a box still
              // reads on a pale or same-coloured map. Lifted from coilbox's
              // own lobby overlay, which had the same problem.
              boxShadow: "0 0 0 1px rgba(0,0,0,0.6)",
            }}
          />
        ))}
        {dots.map((dot, index) => (
          <span
            key={index}
            className="absolute size-1.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-1 ring-black/60"
            style={{
              left: `${dot.x * 100}%`,
              top: `${dot.y * 100}%`,
              background: colorOf(dot.side),
            }}
          />
        ))}
      </div>
      <figcaption className="flex flex-col gap-0.5 text-xs text-neutral-400">
        <span className="text-neutral-300">{name}</span>
        {note ? <span>{note}</span> : null}
        {boxes.length > 0 ? (
          <span>
            Boxes are this map&rsquo;s usual layout for {boxes.length} teams.
            Which team starts where is decided at launch.
          </span>
        ) : null}
        {dots.length > 0 ? (
          <span>Dots are the spawn points BAR lays out for this size.</span>
        ) : null}
      </figcaption>
    </figure>
  );
}

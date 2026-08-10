import Image from "next/image";
import type { BarMap } from "@/lib/bar/maps";
import { previewAtSize } from "@/lib/bar/previewUrl";
import type { StartLayout } from "@/lib/bar/startLayout";

/**
 * The map an item is played on, from BAR's picture of it.
 *
 * The hub stores no images, and until now a preset said which map it was for
 * in text alone. BAR publishes a thumbnail and the start geometry for every map
 * it certifies, so for a listed map this is the real thing rather than a
 * drawing standing in for one.
 *
 * The overlay is honest about what it knows. Boxes are BAR's layout for a
 * battle of this shape, drawn in the participants' own colours so they read
 * against the composition beside them, but which team takes which box is
 * settled at launch and the note underneath says so. Spawn dots only appear
 * where BAR described a layout for exactly this many players a side.
 */

/** Wide enough for the largest slot the page gives it on a retina screen, and
 * a fifth the weight of the 1024 the list hands out. */
const PREVIEW_SIZE = 512;

export function MapMinimap({
  map,
  layout,
  allyColors,
  note,
}: {
  map: BarMap;
  layout: StartLayout;
  /** Ally team index to CSS colour, from the item's own participants. Empty
   * for anything with no composition, such as a setup pack. */
  allyColors: string[];
  /** How start positions get chosen, when the payload says. */
  note: string | null;
}) {
  const preview = map.images?.preview;
  if (!preview) return null;

  const colorOf = (index: number) => allyColors[index] ?? "#e5e5e5";
  const { boxes, dots } = layout;

  return (
    <figure className="flex flex-col gap-2">
      <div
        className="relative overflow-hidden rounded-md border border-neutral-800 bg-black"
        style={{ aspectRatio: `${map.mapWidth ?? 1} / ${map.mapHeight ?? 1}` }}
      >
        <Image
          src={previewAtSize(preview, PREVIEW_SIZE)}
          alt={`Minimap of ${map.displayName}`}
          fill
          sizes="(min-width: 640px) 20rem, 100vw"
          // BAR's own image proxy has already fitted and compressed this, so
          // running it through a second optimiser would cost money to make it
          // no smaller.
          unoptimized
        />
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
        <span className="text-neutral-300">{map.displayName}</span>
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

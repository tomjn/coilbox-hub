import { AssetPlaceholder } from "@/components/AssetPlaceholder";
import type { ResolvedAsset } from "@/lib/assets/resolve";

/**
 * The map an item is played on.
 *
 * The hub's own stored minimap (issue #109) where it holds one, and a
 * placeholder otherwise, so this component always has something to show and the
 * page never has a hole in it where a picture failed to load.
 *
 * There used to be a third answer ahead of both: BAR's own thumbnail, fetched
 * per render off its validated map list, along with the start boxes and spawn
 * points drawn over it. That is gone. The hub now shows only what it holds
 * itself, so a map it has no picture of gets the drawing rather than somebody
 * else's picture, and no page waits on a third party to render.
 *
 * Nothing shown here can be a substitute for something else. `resolveAsset`
 * stands a buildpic in for a missing render angle and for nothing else, and a
 * minimap has no stand-in, so the caption never has to qualify what it is
 * captioning. Whoever adds a caller that asks for a render must read
 * `served.variant` off the answer before labelling it.
 */

export function MapMinimap({
  name,
  picture,
  note,
  className,
}: {
  /** What to call it, which is the name the item itself carries. */
  name: string;
  /** The hub's own picture of this map, or the placeholder standing in for it.
   * From `lib/gallery/itemPictures.ts`. */
  picture: ResolvedAsset;
  /** How start positions get chosen, when the payload says. */
  note: string | null;
  /** Lands on the figure. `h-full` is what a pack's grid passes, so the
   * captions in a row line up along the bottom however tall each map is. */
  className?: string;
}) {
  // Nothing to show, so the drawing is the answer. The note still applies: how
  // start positions get chosen is a fact about the item rather than about the
  // picture.
  if (picture.from === "placeholder") {
    return (
      <figure className={`flex flex-col gap-2${className ? ` ${className}` : ""}`}>
        <AssetPlaceholder of={picture} />
        {note ? (
          <figcaption className="mt-auto flex flex-col gap-0.5 text-xs text-neutral-400">
            <span>{note}</span>
          </figcaption>
        ) : null}
      </figure>
    );
  }

  // The picture's own proportions, which is the only thing left that says what
  // shape a map the hub was handed a picture of actually is.
  const shape = { width: picture.width, height: picture.height };

  return (
    <figure className={`flex flex-col gap-2${className ? ` ${className}` : ""}`}>
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
      <figcaption className="mt-auto flex flex-col gap-0.5 text-xs text-neutral-400">
        <span className="text-neutral-300">{name}</span>
        {note ? <span>{note}</span> : null}
      </figcaption>
    </figure>
  );
}

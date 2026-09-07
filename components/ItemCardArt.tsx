import { AssetPlaceholder } from "@/components/AssetPlaceholder";
import { ConquestGalaxyArt, WarpathRunArt } from "@/components/ItemPreview";
import { KindIcon } from "@/components/KindIcon";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import type { CardShape } from "@/lib/gallery/cardShapes";
import { chooseItemCardArt } from "@/lib/gallery/itemCardArt";
import type { ItemSummary } from "@/lib/gallery/query";

/**
 * The art slot at the top of every gallery card (issue #308).
 *
 * A small component of its own rather than branches inlined into
 * `components/ItemCard.tsx`, because sibling issues (#309 for a challenge,
 * #310 for a blueprint) were always going to widen what draws here, and
 * neither should have to touch the card's own layout to do it.
 * `lib/gallery/itemCardArt.ts` carries the pure "which art does this row get"
 * decision, so this component only has to turn that decision into markup.
 *
 * ## Every card is the same box
 *
 * `FRAME` is a fixed aspect ratio, drawn whether the slot ends up holding a
 * real picture, the drawn placeholder, or a plain kind plate, so the grid does
 * not reflow row to row as pictures resolve or as a filter changes which kinds
 * are on the page. That is different from `components/MapCard.tsx`, where
 * every card is a picture of a map and the box takes that map's own
 * proportions: a gallery card mixes maps with plates in one grid, and only a
 * ratio that does not depend on what is drawn keeps every card the same shape.
 * A real picture is cropped to fit with `object-cover` rather than stretched,
 * so a tall map is not squashed to fill a wide box.
 */

const FRAME =
  "aspect-[3/2] w-full shrink-0 overflow-hidden rounded-md border border-neutral-800 bg-black";

export function ItemCardArt({
  item,
  picture,
  shape,
}: {
  item: Pick<ItemSummary, "kind" | "mode" | "map_name">;
  /** The map's picture keyed on `item.map_name`, from a page level batched
   *  lookup (`lib/gallery/cardPictures.ts`), or `undefined` for a row nothing
   *  was looked up for. */
  picture: ResolvedAsset | undefined;
  /**
   * This card's galaxy, run or layout, from the other page level batched
   * lookup (`lib/gallery/cardShapes.ts`). Undefined for a kind that has no
   * drawing and for one that could not be rebuilt.
   *
   * A challenge's galaxy or run is drawn below (issue #309). A blueprint's
   * layout is still unread: #310 is what adds that branch, the same way this
   * one was added to `chooseItemCardArt` rather than here.
   */
  shape?: CardShape;
}) {
  const choice = chooseItemCardArt(item, picture, shape);

  if (choice.type === "map") {
    if (choice.picture.from === "placeholder") {
      // `quiet`, because the card already prints `item.map_name` of its own
      // right below this slot (`components/ItemCard.tsx`), and the drawing
      // saying it again reads the name twice.
      return <AssetPlaceholder of={choice.picture} className={FRAME} quiet />;
    }

    return (
      <div className={FRAME}>
        {/* A plain img and not next/image, the same reason
            `components/MapCard.tsx` gives: the Hobby allowance is around 5,000
            transformations a month metered on unique source images, which is
            one per map in existence. */}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={choice.picture.url}
          alt={`Minimap of ${item.map_name}`}
          loading="lazy"
          decoding="async"
          className="size-full object-cover"
        />
      </div>
    );
  }

  if (choice.type === "art") {
    // Decorative for the same reason the plate below is: the badge elsewhere
    // on the card already says "Conquest" or "Warpath" in words
    // (`components/ItemCard.tsx`), so the drawing has nothing left to
    // announce. `size-full` fills the same fixed frame every other card art
    // fills, so the grid does not reflow between a map, a galaxy, a run and a
    // plate.
    return (
      <div aria-hidden className={FRAME}>
        {choice.shape.type === "galaxy" ? (
          <ConquestGalaxyArt shape={choice.shape.galaxy} className="size-full" decorative />
        ) : (
          <WarpathRunArt shape={choice.shape.run} className="size-full" decorative />
        )}
      </div>
    );
  }

  // Decorative: the badge elsewhere on the card already names the kind in
  // words (`components/ItemCard.tsx`), so nothing here needs its own label.
  return (
    <div aria-hidden className={`${FRAME} flex items-center justify-center bg-neutral-900`}>
      <KindIcon kind={item.kind} mode={item.mode} className="w-10 text-neutral-700" />
    </div>
  );
}

import Link from "next/link";
import { AssetPlaceholder } from "@/components/AssetPlaceholder";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import { mapSizeLabel, mapTitle, playerCountLabel } from "@/lib/maps/labels";
import { type Filters, filterHref, type MapSummary } from "@/lib/maps/query";

/**
 * One map in the catalog listing (issue #189).
 *
 * Unlike `components/ItemCard.tsx`, this one leads with a picture. A gallery
 * card is a thing somebody wrote about and a title is most of what says which
 * one it is, while a map is a shape, and a reader scanning for a map knows the
 * one they want by looking at it.
 *
 * ## The frame is the map, not the picture
 *
 * The box is drawn at the catalog's own proportions and the picture is stretched
 * to fill it, which is the reading `components/MapFigure.tsx` sets out at
 * length: a minimap is a picture of the whole map however the archive stored it,
 * so mapping the whole picture onto the whole frame is the one answer that is
 * always right.
 *
 * A map with nothing stored gets the drawing rather than a hole, at the same
 * proportions, so a 12 x 20 map with no picture reads as a 12 x 20 rather than
 * as a square. That is the last rung of the ladder in `lib/assets/resolve.ts`
 * and it cannot fail, which is why this component has no third case.
 *
 * ## The words are `lib/maps/labels.ts`
 *
 * `12 x 20` and `8 players` are the same sentences a map's own page prints, from
 * the same functions, so a map is not described one way in a list and another
 * way one click later.
 */

/** A row of chips, so the tags on a card and the tags on a map's own page look
 *  like the same thing. */
const CHIP =
  "inline-block rounded bg-neutral-900 px-2 py-1 text-xs text-neutral-400 transition-colors hover:text-neutral-200";

export function MapCard({
  map,
  picture,
  filters,
}: {
  map: MapSummary;
  /** The hub's own minimap, or the drawing standing in for it, from
   *  `mapPictures` in `lib/maps/query.ts`. */
  picture: ResolvedAsset;
  /** What the reader is already filtering by, so a chip on this card adds to it
   *  rather than replacing it. */
  filters: Filters;
}) {
  const title = mapTitle(map.display_name, map.map_name);
  const players = playerCountLabel(map.start_positions);

  return (
    <article className="flex h-full flex-col gap-3 rounded-md border border-neutral-800 bg-neutral-950 p-4">
      <Link href={`/map/${map.slug}`} className="block">
        {picture.from === "placeholder" ? (
          <AssetPlaceholder of={picture} />
        ) : (
          <div
            className="overflow-hidden rounded-md border border-neutral-800 bg-black"
            style={{ aspectRatio: `${map.width_elmos} / ${map.height_elmos}` }}
          >
            {/* A plain img and not next/image, the same as
                components/MapFigure.tsx: the Hobby allowance is around 5,000
                transformations a month metered on unique source images, which is
                one per map in existence. */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={picture.url}
              alt={`Minimap of ${map.map_name}`}
              className="size-full object-fill"
            />
          </div>
        )}
      </Link>

      <div className="flex flex-col gap-1">
        <h2 className="min-w-0 break-words text-base font-medium leading-snug">
          <Link href={`/map/${map.slug}`} className="hover:underline">
            {title}
          </Link>
        </h2>
        {/* The canonical name, which is what a lobby shows and what somebody
            searching for the archive has to type. Only when the archive gave a
            friendlier name, since that name identifies the map nowhere else. */}
        {title === map.map_name ? null : (
          <p className="break-words text-xs text-neutral-500">{map.map_name}</p>
        )}
      </div>

      <dl className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-400">
        <div className="flex gap-1">
          <dt className="sr-only">Size</dt>
          <dd>{mapSizeLabel(map.width_elmos, map.height_elmos)}</dd>
        </div>
        {/* Absent rather than "0 players" on a map with no start positions,
            which is an incomplete extraction rather than a map nobody can
            play. lib/maps/labels.ts holds that reading. */}
        {players ? (
          <div className="flex gap-1">
            <dt className="sr-only">Players</dt>
            <dd>{players}</dd>
          </div>
        ) : null}
        {map.author_keys.length > 0 ? (
          <div className="flex gap-1">
            <dt className="sr-only">Made by</dt>
            <dd>
              by{" "}
              {map.author_keys.map((key, index) => (
                <span key={key}>
                  {index > 0 ? ", " : null}
                  {/* The link carries the key rather than the name, so it finds
                      every map that person made including the ones they signed
                      under a clan tag or an older handle. */}
                  <Link
                    href={filterHref(filters, { author: key })}
                    className="transition-colors hover:text-neutral-200"
                  >
                    {map.author_names[index] ?? key}
                  </Link>
                </span>
              ))}
            </dd>
          </div>
        ) : null}
      </dl>

      {map.tags.length > 0 ? (
        <ul className="mt-auto flex flex-wrap gap-1.5 pt-1">
          {map.tags.map((tag) => (
            <li key={tag}>
              <Link href={filterHref(filters, { tag })} className={CHIP}>
                {tag}
              </Link>
            </li>
          ))}
        </ul>
      ) : null}
    </article>
  );
}

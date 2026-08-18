import { AssetPlaceholder } from "@/components/AssetPlaceholder";
import type { MapFacts } from "@/lib/api/mapLookup";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import type { MapPoint, MapPoints } from "@/lib/maps/facts";
import { markerPosition } from "@/lib/maps/page";

/**
 * A map's minimap with the points drawn on it (#190).
 *
 * This is what BAR's own list used to give and what #180 removed when the hub
 * stopped showing pictures it does not hold. It is back on the hub's own terms:
 * the hub's stored minimap, the hub's stored points, and no request to anybody
 * else's server while a page renders.
 *
 * ## The frame is the map, not the picture
 *
 * The box is drawn at the catalog's own proportions and the picture is stretched
 * to fill it. A minimap is a picture of the whole map however the archive stored
 * it, and archives store them at whatever size suited the engine, so mapping the
 * whole picture onto the whole frame is the one reading that is always right.
 *
 * It is also what makes the markers land. A point is in world coordinates, so
 * its place in the frame is `x / width_elmos` across and `z / height_elmos`
 * down, and that only holds if the frame is the map's extent. Drawing the box at
 * the picture's proportions instead would put every marker on a 12 x 20 map in
 * the wrong place, and on a square map it would look fine, which is why
 * `markerPosition` is a tested function rather than two divisions in the markup.
 *
 * ## The toggles are checkboxes and the page ships no JavaScript
 *
 * A metal layer that only appears once a bundle has loaded is a layer that does
 * not appear for somebody on a bad connection or with scripting off, and there
 * is nothing here that needs a script: showing and hiding a layer is what a
 * checkbox and a sibling selector have always done. `app/moderation/assets` runs
 * its whole review queue this way.
 *
 * The inputs sit in the figure rather than in a row of their own because a
 * sibling selector reaches siblings and nothing else. Their labels are ordinary
 * visible labels, so the control is a real checkbox with a real name and the
 * only thing hidden is the box itself, which the layer appearing already
 * answers.
 *
 * The layers are `aria-hidden`. Markers on an image say nothing to a screen
 * reader, and the facts beside the picture already state the player count in
 * words, so announcing a list of dots would add noise rather than information.
 */

/** The two inputs a label has to name. Ids rather than a wrapping label, since
 *  the input has to stay a sibling of the layer it shows. One map's page holds
 *  one figure, so a fixed pair is enough. */
const METAL = "map-metal-spots";
const GEO = "map-geo-vents";

/** Half the dot, so a marker is centred on its coordinate rather than hanging
 *  down and to the right of it. */
const CENTRED = "-translate-x-1/2 -translate-y-1/2";

const MARKER = `absolute size-2 rounded-full ring-1 ring-black/70 ${CENTRED}`;

/** In flow, so the chips line up along the bottom of the picture without a
 *  hand placed offset, and relative so they paint over the layers rather than
 *  under them. */
const CHIP =
  "relative cursor-pointer rounded-full border border-neutral-700 bg-black/70 px-3 py-1 text-xs text-neutral-300 backdrop-blur-sm transition-colors hover:border-neutral-500 hover:text-white";

const DOT = "mr-1.5 inline-block size-2 rounded-full align-middle";

function Layer({
  points,
  map,
  colour,
  className,
}: {
  points: MapPoint[];
  map: Pick<MapFacts, "width_elmos" | "height_elmos">;
  colour: string;
  className?: string;
}) {
  if (points.length === 0) return null;

  return (
    <ul aria-hidden className={`absolute inset-0${className ? ` ${className}` : ""}`}>
      {points.map((point, index) => {
        const { left, top } = markerPosition(point, map);
        return (
          <li
            // The index is the stored ordinal, which is the team index on a
            // start position, and two points can share a coordinate.
            key={index}
            className={`${MARKER} ${colour}`}
            style={{ left: `${left}%`, top: `${top}%` }}
          />
        );
      })}
    </ul>
  );
}

export function MapFigure({
  name,
  map,
  points,
  picture,
  className,
}: {
  /** The canonical name, which is what the picture is captioned by. It sits
   *  beside the facts rather than in them, so it is passed in beside them. */
  name: string;
  map: Pick<MapFacts, "width_elmos" | "height_elmos">;
  points: MapPoints;
  picture: ResolvedAsset;
  className?: string;
}) {
  // Nothing stored, so the drawing is the answer, and it is drawn at the shape
  // the catalog says the map is. No markers over it: the drawing is a dashed box
  // standing in for a picture rather than a frame the coordinates mean anything
  // in, and dots on it would claim a precision that is not there.
  if (picture.from === "placeholder") {
    return (
      <figure className={className}>
        <AssetPlaceholder of={picture} />
      </figure>
    );
  }

  return (
    <figure className={`flex flex-col gap-2${className ? ` ${className}` : ""}`}>
      <div
        className="relative flex flex-wrap items-end gap-1.5 overflow-hidden rounded-md border border-neutral-800 bg-black p-2"
        style={{ aspectRatio: `${map.width_elmos} / ${map.height_elmos}` }}
      >
        {/* Before the layers, because a sibling selector only reaches forwards.
            Absolutely positioned by `sr-only`, so neither one takes a place in
            the row the chips sit in. */}
        <input id={METAL} type="checkbox" className="peer/metal sr-only" />
        <input id={GEO} type="checkbox" className="peer/geo sr-only" />

        {/* A plain img and not next/image, the same as components/MapMinimap.tsx:
            the Hobby allowance is around 5,000 transformations a month metered on
            unique source images, which is one per map in existence. Stretched
            rather than cropped, because the whole picture is the whole map. */}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={picture.url}
          alt={`Minimap of ${name}`}
          className="absolute inset-0 size-full object-fill"
        />

        <Layer
          points={points.metal}
          map={map}
          colour="bg-amber-300"
          className="hidden peer-checked/metal:block"
        />
        <Layer
          points={points.geo}
          map={map}
          colour="bg-rose-400"
          className="hidden peer-checked/geo:block"
        />
        {/* Last, so a start position is never hidden under a metal spot sitting
            on top of it. Always drawn: it is the fact the picture is here to
            carry. */}
        <Layer points={points.start} map={map} colour="bg-neutral-100" />

        {points.metal.length > 0 ? (
          <label
            htmlFor={METAL}
            className={`${CHIP} peer-checked/metal:border-neutral-300 peer-checked/metal:bg-neutral-100 peer-checked/metal:text-neutral-900 peer-focus-visible/metal:outline-2 peer-focus-visible/metal:outline-offset-2 peer-focus-visible/metal:outline-neutral-300`}
          >
            <span className={`${DOT} bg-amber-300`} />
            Metal spots
          </label>
        ) : null}
        {points.geo.length > 0 ? (
          <label
            htmlFor={GEO}
            className={`${CHIP} peer-checked/geo:border-neutral-300 peer-checked/geo:bg-neutral-100 peer-checked/geo:text-neutral-900 peer-focus-visible/geo:outline-2 peer-focus-visible/geo:outline-offset-2 peer-focus-visible/geo:outline-neutral-300`}
          >
            <span className={`${DOT} bg-rose-400`} />
            Geo vents
          </label>
        ) : null}
      </div>

      {points.start.length > 0 ? (
        <figcaption className="text-xs text-neutral-400">
          <span className={`${DOT} bg-neutral-100`} />
          Start positions, as the map declares them. How a lobby uses them is the
          lobby&rsquo;s choice.
        </figcaption>
      ) : null}
    </figure>
  );
}

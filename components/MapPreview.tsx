"use client";

import { useEffect, useRef, useState } from "react";
import type { MapPreview as MapPreviewData } from "@/lib/maps/preview";
import type { Terrain } from "./mapTerrain";

/**
 * The map's terrain, standing in for its minimap (#194).
 *
 * The flat figure is what the page is served as and what it stays for anybody
 * whose browser cannot draw the ground. Where the ground does draw, it takes the
 * figure's place: it is the same map, the same picture draped over it and the
 * same three layers of points, with the relief the minimap flattens away.
 *
 * ## Nothing three.js is in this file
 *
 * three and the scene that uses it are about half a megabyte, and a map page is
 * mostly read rather than played with. So the whole of it sits behind an
 * `await import("./mapTerrain")`, which is the one shape Next splits into a
 * chunk of its own. A map page ships this component and the flat figure, `/maps`
 * ships neither, and the library is fetched after the page has been painted and
 * read rather than before.
 *
 * The type crosses the boundary and the code does not. `import type` is erased
 * at compile time, so naming `Terrain` here costs nothing at runtime. A plain
 * `import` of the same module would undo the whole arrangement without anything
 * looking different.
 *
 * ## The flat figure is rendered whatever happens
 *
 * It arrives with the page, from the server, with no script in it. It is then
 * hidden rather than unmounted, so a browser that draws the terrain and a
 * browser that does not are served the same HTML and only differ in what they
 * end up showing. The scene's frame is likewise mounted from the start and
 * measures nothing until it is shown, which is what the `ResizeObserver` in
 * `mapTerrain.ts` is there to notice.
 *
 * ## A failure is quiet, because nobody asked
 *
 * This used to be a button, and a view somebody presses a button for owes them
 * an explanation when it does not arrive. Nothing is pressed now. A browser with
 * no WebGL, a picture that will not load and a canvas that cannot be read all
 * leave the reader looking at the minimap they were already looking at, which is
 * the whole map, correctly drawn. A line of apology under it would report a
 * failure that cost them nothing.
 *
 * ## Reduced motion is read once, when the scene is built
 *
 * The scene drifts slowly until it is touched, and that is the only thing on the
 * page that moves by itself. Somebody who has asked their system for less motion
 * gets a still view they turn themselves. The preference is read as the scene is
 * built rather than subscribed to, because the alternative is rebuilding a WebGL
 * scene under somebody who changed a system setting mid session.
 */

/** How tall the scene is drawn, matching the figure it replaces so that the
 *  page does not resettle when one takes over from the other. */
const HEIGHT = "h-[512px]";

/** The flat figure's own chip, so the controls over the terrain and the
 *  controls over the minimap are recognisably one pair. A button rather than a
 *  checkbox: there is no script-free version of turning a layer of a WebGL scene
 *  on, and this whole component only exists once a script is running. */
const CHIP =
  "cursor-pointer rounded-full border border-neutral-700 bg-black/70 px-3 py-1 text-xs text-neutral-300 backdrop-blur-sm transition-colors hover:border-neutral-500 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 aria-pressed:border-neutral-300 aria-pressed:bg-neutral-100 aria-pressed:text-neutral-900";

const DOT = "mr-1.5 inline-block size-2 rounded-full align-middle";

/** The vent's swatch is the shape the vent is drawn as rather than a dot, which
 *  is also what keeps it apart from the metal spots. The two are close in colour
 *  and nothing else about them is alike. */
const PLUME = "mr-1.5 inline-block h-3 w-1 rounded-full align-middle";

/** How the scene got on. `building` is the ordinary state of the whole page for
 *  the moment it takes to fetch a chunk and read two pictures. */
type Progress = "building" | "drawn" | "failed";

export function MapPreview({
  preview,
  name,
  children,
}: {
  preview: MapPreviewData;
  /** The canonical name, for the caption under the scene. */
  name: string;
  /** The flat figure, rendered on the server. Shown until the terrain is, and
   *  shown for good if it never is. */
  children: React.ReactNode;
}) {
  const [progress, setProgress] = useState<Progress>("building");
  const [layers, setLayers] = useState({ metal: false, geo: false });
  const hostRef = useRef<HTMLDivElement | null>(null);
  const terrainRef = useRef<Terrain | null>(null);

  /**
   * The scene is built in an effect rather than during a render, because the
   * element it draws into does not exist until React has put it there, and
   * because none of this may run on the server.
   */
  useEffect(() => {
    const host = hostRef.current;
    if (!host) return;

    let cancelled = false;
    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    import("./mapTerrain")
      .then(({ drawTerrain }) => drawTerrain(host, preview, reduceMotion))
      .then((built) => {
        // Gone before the chunk arrived, so there is a scene nobody is looking
        // at and it is taken down rather than left holding a GL context.
        if (cancelled) {
          built.dispose();
          return;
        }
        terrainRef.current = built;
        setProgress("drawn");
      })
      .catch(() => {
        if (!cancelled) setProgress("failed");
      });

    return () => {
      cancelled = true;
      terrainRef.current?.dispose();
      terrainRef.current = null;
    };
  }, [preview]);

  /** The layers a reader has asked for, applied to whatever scene there is.
   *  `progress` is a dependency so that the first run lands after the scene
   *  exists rather than before it. */
  useEffect(() => {
    terrainRef.current?.setLayers(layers);
  }, [layers, progress]);

  const drawn = progress === "drawn";

  return (
    <>
      <div className={drawn ? "hidden" : undefined}>{children}</div>

      <figure className={drawn ? "flex flex-col gap-2" : "hidden"}>
        <div className={`relative w-full ${HEIGHT}`}>
          <div ref={hostRef} className="absolute inset-0" />
          <div className="absolute inset-x-0 bottom-0 flex flex-wrap items-end gap-1.5">
            {preview.points.metal.length > 0 ? (
              <button
                type="button"
                aria-pressed={layers.metal}
                onClick={() => setLayers((shown) => ({ ...shown, metal: !shown.metal }))}
                className={CHIP}
              >
                <span className={`${DOT} bg-amber-300`} />
                Metal spots
              </button>
            ) : null}
            {preview.points.geo.length > 0 ? (
              <button
                type="button"
                aria-pressed={layers.geo}
                onClick={() => setLayers((shown) => ({ ...shown, geo: !shown.geo }))}
                className={CHIP}
              >
                <span className={`${PLUME} bg-yellow-300`} />
                Geo vents
              </button>
            ) : null}
          </div>
        </div>

        <figcaption className="flex flex-col gap-1 text-xs text-neutral-400">
          {preview.points.start.length > 0 ? (
            <span>
              <span className={`${DOT} bg-neutral-100`} />
              Start positions, as the map declares them. How a lobby uses them is
              the lobby&rsquo;s choice.
            </span>
          ) : null}
          <span className="text-neutral-500">
            {name} as the archive measures it. Drag to turn it, scroll to zoom.
            Heights are eight bits deep, which is what a browser can read.
          </span>
        </figcaption>
      </figure>
    </>
  );
}

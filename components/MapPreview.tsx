"use client";

import { useEffect, useRef, useState } from "react";
import type { MapPreview as MapPreviewData } from "@/lib/maps/preview";
import type { Terrain } from "./mapTerrain";

/**
 * The button that turns a map's minimap into its terrain (#194).
 *
 * ## Nothing three.js is in this file
 *
 * three and the scene that uses it are about half a megabyte, and a map page is
 * mostly read rather than played with. So the whole of it sits behind an
 * `await import("./mapTerrain")` inside the click handler, which is the one
 * shape Next splits into a chunk of its own. A map page ships this component and
 * a button, `/maps` ships neither, and the library is fetched the first time
 * somebody asks to see the ground.
 *
 * The type crosses the boundary and the code does not. `import type` is erased
 * at compile time, so naming `Terrain` here costs nothing at runtime. A plain
 * `import` of the same module would undo the whole arrangement without anything
 * looking different.
 *
 * ## Reduced motion is read once, when the view opens
 *
 * The scene drifts slowly until it is touched, and that is the only thing on the
 * page that moves by itself. Somebody who has asked their system for less motion
 * gets a still view they turn themselves. The preference is read at the moment
 * they press the button rather than subscribed to, because the alternative is
 * rebuilding a WebGL scene under somebody who changed a system setting mid
 * session.
 *
 * ## A failure is said out loud
 *
 * This is a view somebody asked for. A browser with no WebGL, a picture that
 * will not load and a canvas that cannot be read all end up in the same place,
 * which is a line of text where the terrain would have been. Leaving an empty
 * box would be worse than saying it did not work.
 */

const BUTTON =
  "self-start rounded border border-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:border-neutral-600 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-500";

/** How the scene got on, which is not the same question as whether it was asked
 *  for. See why the two are separate below. */
type Progress = "building" | "drawn" | "failed";

export function MapPreview({ name, preview }: { name: string; preview: MapPreviewData }) {
  const [opened, setOpened] = useState(false);
  const [progress, setProgress] = useState<Progress>("building");
  const hostRef = useRef<HTMLDivElement | null>(null);

  /**
   * The scene is built in an effect rather than in the click handler, because
   * the element it draws into does not exist until React has rendered it.
   *
   * Only `opened` is a dependency, and it only ever goes from false to true.
   * `progress` is deliberately not one: an effect that re-ran when the scene
   * reported success would tear that scene straight back down in its own
   * cleanup, leaving an empty frame and no error to explain it.
   */
  useEffect(() => {
    if (!opened) return;

    const host = hostRef.current;
    if (!host) return;

    let terrain: Terrain | null = null;
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
        terrain = built;
        setProgress("drawn");
      })
      .catch(() => {
        if (!cancelled) setProgress("failed");
      });

    return () => {
      cancelled = true;
      terrain?.dispose();
    };
  }, [opened, preview]);

  if (!opened) {
    return (
      <button type="button" onClick={() => setOpened(true)} className={BUTTON}>
        See the terrain
      </button>
    );
  }

  if (progress === "failed") {
    return (
      <p className="text-xs text-neutral-500">
        The terrain would not draw in this browser. The minimap above is the same
        map, flat.
      </p>
    );
  }

  return (
    <figure className="flex flex-col gap-2">
      <div
        ref={hostRef}
        /**
         * Four by three whatever shape the map is, unlike the flat figure above
         * it, which is drawn at the map's own proportions because a minimap is a
         * picture of the map seen from directly overhead.
         *
         * This is not. The ground is seen from an angle and it turns, so what it
         * sweeps out is a circle of its own diagonal, squashed by the angle it is
         * looked at from. That shape is much the same for a square map and for a
         * 12 x 20, and giving a tall map a tall frame would leave the terrain a
         * band across the middle of it.
         *
         * Fixed, so the page does not jump when the scene arrives.
         */
        className="relative aspect-4/3 w-full overflow-hidden rounded-md border border-neutral-800 bg-black"
      >
        {progress === "building" ? (
          <p className="absolute inset-0 grid place-items-center text-xs text-neutral-500">
            Building the terrain
          </p>
        ) : null}
      </div>
      <figcaption className="text-xs text-neutral-500">
        {name} as the archive measures it. Drag to turn it, scroll to zoom. Heights
        are eight bits deep, which is what a browser can read.
      </figcaption>
    </figure>
  );
}

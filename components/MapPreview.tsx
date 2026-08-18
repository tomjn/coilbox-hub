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

type State = "closed" | "loading" | "open" | "failed";

export function MapPreview({ name, preview }: { name: string; preview: MapPreviewData }) {
  const [state, setState] = useState<State>("closed");
  const hostRef = useRef<HTMLDivElement | null>(null);

  // The scene is built in an effect rather than in the click handler, because
  // the element it draws into does not exist until React has rendered it.
  useEffect(() => {
    if (state !== "loading") return;

    const host = hostRef.current;
    if (!host) return;

    let terrain: Terrain | null = null;
    let cancelled = false;
    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    import("./mapTerrain")
      .then(({ drawTerrain }) => drawTerrain(host, preview, reduceMotion))
      .then((built) => {
        // Closed again before the chunk arrived, so there is a scene nobody
        // asked for any more and it is taken down rather than left holding a GL
        // context.
        if (cancelled) {
          built.dispose();
          return;
        }
        terrain = built;
        setState("open");
      })
      .catch(() => {
        if (!cancelled) setState("failed");
      });

    return () => {
      cancelled = true;
      terrain?.dispose();
    };
  }, [state, preview]);

  if (state === "closed") {
    return (
      <button type="button" onClick={() => setState("loading")} className={BUTTON}>
        See the terrain
      </button>
    );
  }

  if (state === "failed") {
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
        // The map's own proportions, so the ground is not squashed into a shape
        // it is not, and a fixed frame so the page does not jump when the scene
        // arrives.
        style={{ aspectRatio: `${preview.widthElmos} / ${preview.heightElmos}` }}
        className="relative w-full overflow-hidden rounded-md border border-neutral-800 bg-black"
      >
        {state === "loading" ? (
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

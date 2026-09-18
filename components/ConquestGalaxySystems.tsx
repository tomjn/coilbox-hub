"use client";

/**
 * The interactive half of a labelled conquest galaxy (issue #409): every
 * system's star, and the name that goes with it, either always on for a
 * capital or given up the moment somebody points at that star.
 *
 * A client component because "what is somebody pointing at right now" is
 * state, and `components/ItemPreview.tsx`'s `ConquestGalaxyArt` is a server
 * component with nowhere to hold it. Everything this needs is already
 * resolved to plain numbers and strings by that caller, geometry included, so
 * this holds no galaxy knowledge of its own: it only draws what it is told
 * and tracks which one star, if any, is being pointed at.
 *
 * Pointing at a system is three things, answered the same way for all
 * three so none of them is a second class way in: a mouse hovering the
 * star, the star holding keyboard focus, and a tap, which on every browser
 * this was checked against also fires a click. `onFocus`/`onBlur` alone
 * would leave touch out, and `onMouseEnter`/`onMouseLeave` alone would leave
 * out keyboard and most of touch, which is exactly the mouse-only reveal the
 * issue rules out.
 *
 * Only one star can be revealed at a time (`revealed` is a single index, not
 * a set), which matches how one pointer, mouse or touch, or one keyboard
 * focus actually behaves. It also means a revealed name never has to share
 * the drawing with another revealed name, only with the capitals that are
 * always there. `ConquestGalaxyArt` only ever needs to find a revealed name
 * room against the fixed set of capital boxes, not against every other
 * system that might also be showing.
 *
 * Every star keeps the `<title>` `ConquestGalaxyArt` always gave it, still
 * what a screen reader reads, unchanged by any of this. The reveal here is a
 * sighted, on-drawing addition to it, not a replacement.
 */

import { useState } from "react";

export interface SystemDraw {
  x: number;
  y: number;
  radius: number;
  fill: string;
  /** Absent on a challenge shared before coilbox published names (issue
   *  #397), which draws stars with nothing to point at. */
  name?: string;
  map?: string;
  capital: boolean;
  anchor: "start" | "middle" | "end";
  /** Where this system's name draws, above or below its star, or null if it
   *  has nowhere to go without landing on a capital's name. Always set for a
   *  capital unless two capitals collide. For anything else it is computed
   *  against the capital set up front, so it is ready the instant that
   *  system is pointed at rather than worked out on the fly. */
  textY: number | null;
}

export function ConquestGalaxySystems({
  systems,
  glow,
  fontSize,
  labelColor,
}: {
  systems: readonly SystemDraw[];
  /** The `<filter>` id `ConquestGalaxyArt` already declared in `<defs>`. */
  glow: string;
  /** `ConquestGalaxyArt`'s own label font size, passed down rather than
   *  imported so the box estimate it is measured against and the size drawn
   *  here can never drift apart. */
  fontSize: number;
  /** `ConquestGalaxyArt`'s own label colour, for the same reason. */
  labelColor: string;
}) {
  const [revealed, setRevealed] = useState<number | null>(null);

  const clear = (i: number) => setRevealed((current) => (current === i ? null : current));

  return (
    <>
      <g filter={`url(#${glow})`}>
        {systems.map((system, i) => (
          <circle
            key={i}
            cx={system.x}
            cy={system.y}
            // A capital is a brighter, bigger star. The glow does the rest of
            // the work, so it needs no ring to stand out.
            r={system.radius}
            fill={system.fill}
            tabIndex={system.name ? 0 : undefined}
            className={
              system.name
                ? "cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                : undefined
            }
            onMouseEnter={system.name ? () => setRevealed(i) : undefined}
            onMouseLeave={system.name ? () => clear(i) : undefined}
            onFocus={system.name ? () => setRevealed(i) : undefined}
            onBlur={system.name ? () => clear(i) : undefined}
            // A tap fires this on every browser this was checked against,
            // which is what makes touch work: focus alone is not reliable
            // for a tap on a non-form element across browsers.
            onClick={system.name ? () => setRevealed(i) : undefined}
          >
            {system.name ? (
              <title>
                {system.map ? `${system.name} — ${system.map}` : system.name}
              </title>
            ) : null}
          </circle>
        ))}
      </g>
      {systems.map((system, i) => {
        if (!system.name || system.textY === null) return null;
        // A capital's name is always on. Anything else only gives its name
        // up while it is the one being pointed at.
        if (!system.capital && revealed !== i) return null;
        return (
          <text
            key={i}
            x={system.x}
            y={system.textY}
            // Centred on its star, except at the two edges, where a centred
            // name would run out of the box and be cut in half.
            textAnchor={system.anchor}
            fontSize={fontSize}
            fill={labelColor}
            // Outlined in the page's own black so a revealed name stays
            // readable over a lane or another star it lands near.
            stroke="#000000"
            strokeWidth={0.7}
            strokeLinejoin="round"
            paintOrder="stroke"
          >
            {system.name}
          </text>
        );
      })}
    </>
  );
}

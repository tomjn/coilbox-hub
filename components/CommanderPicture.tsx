import type { SideCommander } from "@/lib/games/sides";

/**
 * A side's start unit, drawn from its buildpic, for the games listing card and
 * the faction tiles on a game's page.
 *
 * Buildpics are square and drawn on their own backgrounds, so the picture
 * fills a square tile rather than floating in one. Decorative in both places:
 * each prints the side's name or the game's name as text, and a screen reader
 * hearing "Buildpic of Commander" twice per card learns nothing the name did
 * not say.
 *
 * A unit the hub holds no picture for gets the dashed outline
 * `components/AssetPlaceholder.tsx` draws, in a tile of the same size, so a row
 * of sides keeps its shape while the pictures are still being seeded. The
 * outline rather than a glyph, because it is the mark the rest of the site
 * already uses for "no picture", and it is drawn small inside the tile rather
 * than filling it so a real buildpic beside it still reads as the picture.
 */

/** Matching `components/AssetPlaceholder.tsx`, so the two read as one drawing
 *  at two sizes. */
const FILL = 0.15;
const OUTLINE = 0.62;

export function CommanderPicture({
  commander,
  className,
}: {
  commander: SideCommander;
  className: string;
}) {
  if (commander.picture.from === "placeholder") {
    return (
      <span
        aria-hidden
        className={`flex shrink-0 items-center justify-center rounded ${className}`}
      >
        <svg viewBox="0 0 24 24" className="w-1/2 text-neutral-300">
          <rect
            x="2"
            y="2"
            width="20"
            height="20"
            rx="4"
            fill="currentColor"
            fillOpacity={FILL}
            stroke="currentColor"
            strokeOpacity={OUTLINE}
            strokeWidth={1.25}
            strokeDasharray="4 4"
            vectorEffect="non-scaling-stroke"
          />
        </svg>
      </span>
    );
  }

  return (
    // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image, see next.config.ts
    <img
      src={commander.picture.url}
      alt=""
      width={commander.picture.width}
      height={commander.picture.height}
      loading="lazy"
      decoding="async"
      className={`shrink-0 rounded object-cover ${className}`}
    />
  );
}

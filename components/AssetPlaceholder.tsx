import {
  type MissingPicture,
  placeholderBox,
  placeholderLabel,
  placeholderMeasure,
} from "@/lib/assets/placeholder";

/**
 * A picture the hub does not have, drawn rather than fetched (issue #108).
 *
 * The last rung of the serving ladder in `lib/assets/resolve.ts`, and the only
 * one that cannot fail. Read `lib/assets/placeholder.ts` for why it is markup in
 * the page rather than a generated image: a placeholder is by definition the
 * case where there is no picture, so it must not cost a request, a function
 * invocation or an image transformation.
 *
 * Drawn in the same graphite as the blueprint plan in
 * `components/ItemPreview.tsx`, at the same weights, so it reads as part of the
 * gallery rather than as a broken frame. The outline is dashed for the reason
 * the plan dashes an unsized building: the shape is real and the picture is not.
 *
 * The caller sizes it. `className` lands on the outer box, which is the same
 * bordered black panel every other no-preview state on the site uses.
 */

/** The stroke, in user units, so a non-scaling stroke is not clipped by the
 *  edge of the `viewBox`. */
const INSET = 2;

/** The corner, in user units. The box's longer side is always 100, so this is
 *  the same corner at every footprint. */
const CORNER = 4;

/**
 * How much colour the shape takes.
 *
 * The outline is the weight `components/ItemPreview.tsx` draws a building at, so
 * a placeholder beside a plan is recognisably the same drawing. The fill is half
 * of it. A plan is a few dozen small squares and this is one large one, and at
 * the plan's own fill a single block that stands for nothing drew more ink than
 * the real pictures around it.
 */
const FILL = 0.15;
const OUTLINE = 0.62;

export function AssetPlaceholder({
  of,
  className,
  quiet,
}: {
  of: MissingPicture;
  className?: string;
  /** No caption. For callers that print the name themselves right below, where
   *  the drawing saying it too reads the name twice (#280) - and a def key with
   *  no spaces in it cannot wrap, so it walks out of the box and over its
   *  neighbours. */
  quiet?: boolean;
}) {
  const box = placeholderBox(of.footprint);
  const measure = placeholderMeasure(of);

  return (
    <div
      className={`flex flex-col items-center justify-center gap-3 rounded-md border border-neutral-800 bg-black p-4${className ? ` ${className}` : ""}`}
    >
      <svg
        viewBox={`0 0 ${box.width} ${box.height}`}
        // Sized by the box it is in, and never blown up past a thumbnail: this
        // is a stand-in, and a large one would draw more attention than the
        // pictures around it that are real.
        className="w-full max-w-32 text-neutral-300"
        style={{ aspectRatio: `${box.width} / ${box.height}` }}
        role="img"
        aria-label={placeholderLabel(of)}
      >
        <rect
          x={INSET}
          y={INSET}
          width={box.width - INSET * 2}
          height={box.height - INSET * 2}
          rx={CORNER}
          fill="currentColor"
          fillOpacity={FILL}
          stroke="currentColor"
          strokeOpacity={OUTLINE}
          // In pixels rather than user units, so a wide map and a square
          // building get the same hairline instead of one scaled by its own
          // proportions.
          strokeWidth={1.25}
          strokeDasharray="4 4"
          vectorEffect="non-scaling-stroke"
        />
      </svg>
      {quiet ? null : (
        <p className="flex max-w-full flex-col items-center gap-0.5 text-center text-xs">
          <span className="break-all text-neutral-300">{of.name}</span>
          <span className="break-all text-neutral-500">
            {measure ? `${measure}, no picture yet` : "No picture yet"}
          </span>
        </p>
      )}
    </div>
  );
}

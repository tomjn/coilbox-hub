import { AssetPlaceholder } from "@/components/AssetPlaceholder";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import type { UnitRenderView } from "@/lib/games/units";

/**
 * A unit's pictures, drawn apart (#268).
 *
 * The buildpic leads: it sits beside the name as the hero portrait, which is
 * the picture a player knows a unit by. The renders have their own section
 * further down the page, where a large image has room to breathe on every
 * screen - side by side made sense in a mockup and fought the layout on
 * everything wider than a phone.
 */

/** The hero portrait: the buildpic when the hub holds one, otherwise whatever
 *  the render resolution found. */
export function UnitPortrait({
  label,
  asset,
}: {
  label: string;
  asset: ResolvedAsset;
}) {
  return (
    <figure className="flex w-40 shrink-0 flex-col items-center justify-center gap-2 rounded-md border border-neutral-900 bg-black p-4">
      {asset.from === "placeholder" ? (
        <AssetPlaceholder of={asset} className="w-full" />
      ) : (
        // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image; see next.config.ts
        <img
          src={asset.url}
          alt={`Picture of ${label}`}
          width={asset.width}
          height={asset.height}
          decoding="async"
          className="h-auto w-full object-contain"
        />
      )}
      <figcaption className="text-xs text-neutral-500">Buildpic</figcaption>
    </figure>
  );
}

/**
 * What a caption calls each angle. The vocabulary's own names are keys and not
 * words for a reader, so `top` reads "Top down" rather than being shown raw.
 *
 * An angle added upstream falls through to its own name, which is a plain word
 * in every angle the vocabulary has ever carried. That beats a caption reading
 * "undefined" over a picture the hub is holding perfectly well.
 */
const ANGLE_CAPTIONS: Record<string, string> = {
  top: "Top down",
  front: "Front",
  side: "Side",
  angled: "Angled",
};

/** One angle, or nothing when the hub holds no render at that angle - an empty
 *  figure would promise a picture that is not there. A substituted one is the
 *  buildpic, which the portrait above is already showing. */
function UnitRenderFigure({ label, render }: { label: string; render: UnitRenderView }) {
  const { angle, asset } = render;
  if (asset.from === "placeholder" || asset.substituted) return null;

  const caption = ANGLE_CAPTIONS[angle] ?? angle;

  return (
    <figure className="flex flex-col items-center gap-2 rounded-md border border-neutral-900 bg-black p-6">
      {/* eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image, see next.config.ts */}
      <img
        src={asset.url}
        alt={`${caption} render of ${label}`}
        width={asset.width}
        height={asset.height}
        decoding="async"
        className="h-auto max-w-sm object-contain"
      />
      <figcaption className="text-xs text-neutral-500">{caption}</figcaption>
    </figure>
  );
}

/**
 * Every angle the hub holds, or nothing at all when it holds none.
 *
 * Wrapped rather than left to the page, because whether the section exists
 * depends on what survives the per angle test above and the page would have to
 * repeat that test to know. One render centres, four wrap into pairs, and
 * neither needs a case of its own.
 */
export function UnitRenders({
  label,
  renders,
}: {
  label: string;
  renders: UnitRenderView[];
}) {
  const shown = renders.filter(
    (render) => render.asset.from !== "placeholder" && !render.asset.substituted,
  );
  if (shown.length === 0) return null;

  return (
    <section className="flex flex-col gap-3" aria-labelledby="unit-renders">
      <h2 id="unit-renders" className="text-sm uppercase tracking-wide text-neutral-400">
        Renders
      </h2>
      {/* `items-start` because the angles are different shapes: a side on is
          wider than it is tall and a top down is square, and a stretched row
          would sit each of them in a box the height of the tallest.

          The width cap is what makes four angles read as a square of four
          rather than a row of three and an orphan. A render is capped at 256px
          on its longest edge by the vocabulary, so two figures always fit
          across 42rem and three never do, whatever shape they are. One render
          still centres, which is what this section was before it could hold
          more than one. */}
      <div className="mx-auto flex max-w-2xl flex-wrap items-start justify-center gap-4">
        {shown.map((render) => (
          <UnitRenderFigure key={render.angle} label={label} render={render} />
        ))}
      </div>
    </section>
  );
}

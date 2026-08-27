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
      {/* `my-auto` only bites in the wide row below, where the boxes share a
          height: it centres the angle in its box and drops every caption onto
          one line. The angles are different shapes, so a row of four without it
          reads as four captions at four heights. */}
      {/* eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image, see next.config.ts */}
      <img
        src={asset.url}
        alt={`${caption} render of ${label}`}
        width={asset.width}
        height={asset.height}
        decoding="async"
        className="my-auto h-auto max-w-full object-contain"
      />
      <figcaption className="text-xs text-neutral-500">{caption}</figcaption>
    </figure>
  );
}

/**
 * What a wide screen does with the angles, by how many of them there are.
 *
 * A render is capped at 256px on its longest edge by the vocabulary, so no
 * arrangement makes these bigger and the only thing left to win is seeing every
 * angle at once. Three or four wrap into a second row inside the 42rem column
 * and leave both margins of a 64rem page empty to do it, so from `xl` they
 * straighten into a single row: four break out past the page column to hold
 * their size, three fit across it as it stands. One or two already read right
 * in 42rem, so they are absent here and stay as they are at every width.
 *
 * `items-stretch` is what puts the captions on one line, by giving the boxes in
 * a row one height to share. The flex fallback keeps `items-start` instead,
 * where the same stretch would only pad a short box out to a tall one's height
 * with nothing to show for it.
 *
 * Both rows have to undo the `mx-auto` they inherit, which is a centring margin
 * in the pair layout but makes this a shrink to fit box in a column flex parent,
 * and a row measured 13px inside the page column reads as a mistake rather than
 * as a choice. Four undoes it with the breakout, three with `mx-0`.
 */
const WIDE_ROW: Record<number, string> = {
  3: " xl:mx-0 xl:grid xl:max-w-none xl:grid-cols-3 xl:items-stretch",
  4: " xl:-mx-24 xl:grid xl:max-w-none xl:grid-cols-4 xl:items-stretch",
};

/**
 * Every angle the hub holds, or nothing at all when it holds none.
 *
 * Wrapped rather than left to the page, because whether the section exists
 * depends on what survives the per angle test above and the page would have to
 * repeat that test to know. One render centres, four line up, and neither needs
 * a case of its own here.
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
      {/* Two angles wrap into a pair across 42rem and one centres, which is
          what this section was before it could hold more than one. Every width
          narrower than `xl` still does that, whatever `WIDE_ROW` says above. */}
      <div
        className={`mx-auto flex max-w-2xl flex-wrap items-start justify-center gap-4${
          WIDE_ROW[shown.length] ?? ""
        }`}
      >
        {shown.map((render) => (
          <UnitRenderFigure key={render.angle} label={label} render={render} />
        ))}
      </div>
    </section>
  );
}

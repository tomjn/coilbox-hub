import { AssetPlaceholder } from "@/components/AssetPlaceholder";
import type { ResolvedAsset } from "@/lib/assets/resolve";

/**
 * A unit's pictures, side by side (#259).
 *
 * The top down render leads. The buildpic sits beside it when the hub holds
 * both, because they are different pictures of the same unit rather than one
 * being a stand-in for the other - the substitution rung in the resolver is
 * what serves something when only the buildpic exists, and that single picture
 * keeps its own slot here.
 */

export function UnitPictures({
  label,
  render,
  buildpic,
}: {
  label: string;
  render: ResolvedAsset;
  buildpic: ResolvedAsset;
}) {
  // Without a true render of its own the first slot is already showing the
  // buildpic by substitution, so drawing it twice would be a lie about how
  // many pictures the hub holds.
  const both = render.from !== "placeholder" && !render.substituted && buildpic.from !== "placeholder";

  return (
    <div className="flex shrink-0 items-start gap-3">
      <figure className="flex w-48 flex-col items-center justify-center gap-2 rounded-md border border-neutral-900 bg-black p-4">
        {render.from === "placeholder" ? (
          <AssetPlaceholder of={render} className="w-full" />
        ) : (
          // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image; see next.config.ts
          <img
            src={render.url}
            alt={`Top down render of ${label}`}
            width={render.width}
            height={render.height}
            decoding="async"
            className="h-auto w-full object-contain"
          />
        )}
        {render.from !== "placeholder" ? (
          <figcaption className="text-xs text-neutral-500">Top down render</figcaption>
        ) : null}
      </figure>
      {both ? (
        <figure className="flex w-40 flex-col items-center justify-center gap-2 rounded-md border border-neutral-900 bg-black p-4">
          {/* eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image; see next.config.ts */}
          <img
            src={buildpic.url}
            alt={`Buildpic of ${label}`}
            width={buildpic.width}
            height={buildpic.height}
            decoding="async"
            className="h-auto w-full object-contain"
          />
          <figcaption className="text-xs text-neutral-500">Buildpic</figcaption>
        </figure>
      ) : null}
    </div>
  );
}

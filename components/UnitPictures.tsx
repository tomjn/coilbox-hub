import { AssetPlaceholder } from "@/components/AssetPlaceholder";
import type { ResolvedAsset } from "@/lib/assets/resolve";

/**
 * A unit's two pictures, drawn apart (#268).
 *
 * The buildpic leads: it sits beside the name as the hero portrait, which is
 * the picture a player knows a unit by. The top down render has its own
 * section further down the page, where a large image has room to breathe on
 * every screen - side by side made sense in a mockup and fought the layout on
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

/** The top down render as its own section, or nothing when the hub holds no
 *  real render of its own - an empty section would promise a picture that is
 *  not there. */
export function UnitRenderFigure({
  label,
  render,
}: {
  label: string;
  render: ResolvedAsset;
}) {
  if (render.from === "placeholder" || render.substituted) return null;

  return (
    <figure className="flex flex-col items-center gap-2 rounded-md border border-neutral-900 bg-black p-6">
      {/* eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image; see next.config.ts */}
      <img
        src={render.url}
        alt={`Top down render of ${label}`}
        width={render.width}
        height={render.height}
        decoding="async"
        className="h-auto max-w-sm object-contain"
      />
      <figcaption className="text-xs text-neutral-500">Top down render</figcaption>
    </figure>
  );
}

import { CoilArt } from "@/components/art/CoilArt";
import type { Drawing } from "@/components/art/drawings";

/**
 * The backdrop treatment `app/page.tsx` worked out for the landing page,
 * factored out so every other route reuses it rather than reinventing it.
 * Landing itself is left calling `CoilArt` through `HubArt.tsx` directly
 * rather than switched over to this, so nothing about its already-approved
 * markup changes as part of adding this.
 *
 * Fixed to the viewport rather than the page, so it stays put as the page
 * scrolls instead of moving with the content beneath it. The mask is the
 * fade: full strength at the bottom of the viewport, nothing at the top.
 * `pointer-events-none` and `aria-hidden` keep it out of the way of the
 * page's controls and semantics.
 *
 * A caller renders this as the first child of a `relative` container, then
 * marks its other children `relative z-10` so they paint above it. That is
 * the same recipe `app/page.tsx` uses today.
 */
export function ArtBackdrop({
  drawing,
  strength,
}: {
  drawing: Drawing;
  strength: number;
}) {
  return (
    <div
      aria-hidden
      className="pointer-events-none fixed inset-0 z-0 overflow-hidden"
      style={{
        maskImage: "linear-gradient(to top, black, transparent)",
        WebkitMaskImage: "linear-gradient(to top, black, transparent)",
      }}
    >
      <CoilArt drawing={drawing} className="h-full w-full" strength={strength} />
    </div>
  );
}

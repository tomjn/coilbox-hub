import { GamesIcon } from "@/components/icons";

/**
 * A game's logo in a fixed tile, for the listing card and the game's own
 * page (#358).
 *
 * Logos come in every shape: tall, wide, round and square. Drawn at their own
 * proportions they made every card a different height, so the tile is one size
 * and the picture is scaled to fit inside it. The tile is 3:2, the ratio a
 * gallery card's art slot uses (`components/ItemCardArt.tsx`), so a wide logo
 * is not shrunk to a sliver. It is 64 pixels tall, because an upload is resized
 * to fit 128 pixels (`lib/games/imageResize.ts`), which is 64 on a 2x screen, so
 * a larger tile would only draw a blurrier logo.
 *
 * A game with no logo gets the same tile with the nav's games icon in it, the
 * way a gallery card with no picture gets its kind icon
 * (`components/ItemCardArt.tsx`), so the title beside it still lines up. The
 * icon is decorative, because the title beside it names the game.
 */

const TILE = "h-16 w-24 shrink-0 overflow-hidden rounded-md border border-neutral-800";

export function GameLogo({ src, alt }: { src: string | null; alt: string }) {
  if (!src) {
    return (
      <div aria-hidden className={`${TILE} flex items-center justify-center bg-neutral-900`}>
        <GamesIcon className="w-7 text-neutral-600" />
      </div>
    );
  }

  return (
    <div className={`${TILE} bg-black p-1`}>
      {/* Not next/image, because the hub serves no picture through it (see next.config.ts). */}
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={src}
        alt={alt}
        width={96}
        height={64}
        decoding="async"
        className="size-full object-contain"
      />
    </div>
  );
}

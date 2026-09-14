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
 */
export function CommanderPicture({
  commander,
  className,
}: {
  commander: SideCommander;
  className: string;
}) {
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

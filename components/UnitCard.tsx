import Link from "next/link";
import { AssetPlaceholder } from "@/components/AssetPlaceholder";
import type { ResolvedAsset } from "@/lib/assets/resolve";

/**
 * One unit in the encyclopedia's grid (#227).
 *
 * A buildpic and a name, which is the whole of what a cell needs: a player
 * scanning for a unit knows it by its picture first and its name second, which
 * is the same reading a map card leads with. A unit with no picture stored gets
 * the drawing rather than a hole, so the grid never has gaps shaped like
 * missing files.
 */

export function UnitCard({
  game,
  unit,
  picture,
  eager,
}: {
  game: string;
  unit: { unit_name: string; full_name: string | null };
  picture: ResolvedAsset;
  eager?: boolean;
}) {
  const label = unit.full_name ?? unit.unit_name;
  return (
    <li>
      <Link
        href={`/games/${game}/units/${unit.unit_name}`}
        className="group flex h-full min-w-0 flex-col items-center gap-2 rounded-md border border-neutral-900 p-3 transition-colors hover:border-neutral-600 active:border-neutral-500"
      >
        <span className="flex h-16 w-full items-center justify-center">
          {picture.from === "placeholder" ? (
            // Square and quiet: the cell prints the name below, so the
            // drawing saying it too read every def key twice (#280), and a
            // footprint taller than the slot walked over its neighbours.
            <AssetPlaceholder
              of={{ name: picture.name, keyedOn: picture.keyedOn, footprint: null }}
              quiet
              className="size-16"
            />
          ) : (
            // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image; see next.config.ts
            <img
              src={picture.url}
              alt={`Buildpic of ${label}`}
              width={64}
              height={64}
              loading={eager ? undefined : "lazy"}
              decoding="async"
              className="max-h-16 w-auto object-contain"
            />
          )}
        </span>
        <span className="line-clamp-2 text-center text-xs text-neutral-300 transition-colors group-hover:text-white group-active:text-white">
          {label}
        </span>
      </Link>
    </li>
  );
}

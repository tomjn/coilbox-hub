import Link from "next/link";
import { ItemCard } from "@/components/ItemCard";
import type { Filters, ItemSummary } from "@/lib/gallery/query";

/**
 * What the gallery holds that was made for this map (#190).
 *
 * Nothing at all when there is nothing, rather than a heading over an empty
 * grid. Most maps have nothing published for them and that is ordinary, so a
 * section saying so on every one of them would be a wall of "none yet" between
 * the map and the rest of its page.
 *
 * `ItemCard` and the gallery's own summary columns, so a card here is the card
 * the gallery draws. The filters it is handed are this map's, which is what
 * makes a card's own links land back in a gallery already filtered to the map
 * the reader came from.
 */
export function MapPlayedOn({
  mapName,
  items,
  origin,
}: {
  mapName: string;
  items: ItemSummary[];
  /** Absolute, because coilbox will only fetch an https URL. `ItemCard` builds
   *  an import link out of it. */
  origin: string;
}) {
  if (items.length === 0) return null;

  const filters: Filters = {
    kind: null,
    game: null,
    map: mapName,
    tag: null,
    author: null,
    q: null,
    page: 1,
  };

  return (
    <section className="flex flex-col gap-4 border-t border-neutral-900 pt-6">
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <h2 className="text-xl font-semibold tracking-tight">Played on this map</h2>
        <Link
          href={`/gallery?map=${encodeURIComponent(mapName)}`}
          className="text-sm text-neutral-500 transition-colors hover:text-neutral-300"
        >
          See these in the gallery
        </Link>
      </div>
      <ul className="grid gap-4 sm:grid-cols-2">
        {items.map((item) => (
          <li key={item.id}>
            <ItemCard item={item} filters={filters} origin={origin} />
          </li>
        ))}
      </ul>
    </section>
  );
}

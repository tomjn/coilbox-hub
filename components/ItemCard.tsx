import Link from "next/link";
import { ImportLink } from "@/components/ImportLink";
import { itemLabel } from "@/lib/gallery/label";
import type { Filters, ItemSummary } from "@/lib/gallery/query";
import { filterHref } from "@/lib/gallery/query";

/**
 * A card is text only for now. The preview each kind deserves is its own piece of
 * work, and a card that lies about having one is worse than a card that clearly
 * does not yet.
 */
export function ItemCard({
  item,
  filters,
  origin,
}: {
  item: ItemSummary;
  filters: Filters;
  /** Absolute, because coilbox will only fetch an https URL. A relative path
   * here silently produces a link that cannot be opened. */
  origin: string;
}) {
  return (
    <article className="flex flex-col gap-3 rounded-md border border-neutral-800 bg-neutral-950 p-5">
      <div className="flex items-start justify-between gap-3">
        <h2 className="text-base font-medium leading-snug">
          <Link href={`/item/${item.id}`} className="hover:underline">
            {item.title}
          </Link>
        </h2>
        <span className="shrink-0 rounded border border-neutral-800 px-2 py-0.5 text-xs text-neutral-400">
          {itemLabel(item.kind, item.mode)}
        </span>
      </div>

      {item.description ? (
        <p className="line-clamp-3 text-sm text-neutral-400">
          {item.description}
        </p>
      ) : null}

      <dl className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-500">
        {item.game_name ? (
          <div className="flex gap-1">
            <dt className="sr-only">Game</dt>
            <dd>{item.game_name}</dd>
          </div>
        ) : null}
        {item.map_name ? (
          <div className="flex gap-1">
            <dt className="sr-only">Map</dt>
            <dd>{item.map_name}</dd>
          </div>
        ) : null}
        <div className="flex gap-1">
          <dt className="sr-only">Published by</dt>
          <dd>
            by{" "}
            <Link
              href={filterHref(filters, { author: item.author_name })}
              className="text-neutral-400 transition-colors hover:text-neutral-200"
            >
              {item.author_name}
            </Link>
          </dd>
        </div>
      </dl>

      {item.tags.length > 0 ? (
        <ul className="flex flex-wrap gap-1.5">
          {item.tags.map((tag) => (
            <li key={tag}>
              <Link
                href={filterHref(filters, { tag })}
                className="rounded bg-neutral-900 px-2 py-0.5 text-xs text-neutral-400 transition-colors hover:text-neutral-200"
              >
                {tag}
              </Link>
            </li>
          ))}
        </ul>
      ) : null}

      <div className="mt-1">
        <ImportLink shareUrl={`${origin}/i/${item.id}`} />
      </div>
    </article>
  );
}

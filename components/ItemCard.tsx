import Link from "next/link";
import { ImportLink } from "@/components/ImportLink";
import { ItemCardArt } from "@/components/ItemCardArt";
import { KindIcon } from "@/components/KindIcon";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import { itemLabel } from "@/lib/gallery/label";
import type { Filters, ItemSummary } from "@/lib/gallery/query";
import { filterHref } from "@/lib/gallery/query";

/**
 * A card carries a small picture now (issue #308), reversing #68's "text only"
 * call for the reason #68 itself gave: a card is read at a fraction of a
 * page's size, in a grid of two dozen at once, so anything drawn on it has to
 * survive being small and being one of many.
 *
 * What changed is what gets drawn. #68 was about the per-kind backdrop
 * `app/item/[id]/page.tsx` still shows: one full bleed drawing behind running
 * text on a page nobody else is competing with, and a grid of two dozen of
 * those really would fight each other and the `KindIcon` glyph already on
 * every card. `components/ItemCardArt.tsx` is a different thing: a single
 * picture in a fixed slot, of the actual map a scenario or preset was played
 * on where the hub has one, or a small tinted plate carrying the same
 * `KindIcon` glyph otherwise. That reads as identity rather than as
 * decoration, which is the distinction #68 was drawing, and this issue does
 * not undo it.
 */
export function ItemCard({
  item,
  filters,
  origin,
  picture,
}: {
  item: ItemSummary;
  filters: Filters;
  /** Absolute, because coilbox will only fetch an https URL. A relative path
   * here silently produces a link that cannot be opened. */
  origin: string;
  /** This card's map picture, keyed on `item.map_name`, from a page level
   *  batched lookup (`lib/gallery/cardPictures.ts`). Undefined for a row
   *  nothing was looked up for, which `ItemCardArt` treats as "no picture"
   *  rather than an error. */
  picture?: ResolvedAsset;
}) {
  return (
    <article className="flex h-full flex-col gap-3 rounded-md border border-neutral-800 bg-neutral-950 p-5">
      <ItemCardArt item={item} picture={picture} />
      <div className="flex items-start justify-between gap-3">
        {/* A title is one field of free text and nothing stops it being a single
            120 character word, which without this drags the whole grid sideways. */}
        <h2 className="min-w-0 break-words text-base font-medium leading-snug">
          <Link href={`/item/${item.id}`} className="hover:underline active:underline">
            {item.title}
          </Link>
        </h2>
        <Link
          href={filterHref(filters, { kind: [item.kind] })}
          className="flex shrink-0 items-center gap-1.5 rounded border border-neutral-800 px-2 py-1 text-xs text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
        >
          <KindIcon kind={item.kind} mode={item.mode} className="w-3.5" />
          {itemLabel(item.kind, item.mode)}
        </Link>
      </div>

      {item.description ? (
        <p className="line-clamp-3 text-sm text-neutral-400">
          {item.description}
        </p>
      ) : null}

      <dl className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-400">
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
              href={filterHref(filters, { author: [item.author_name] })}
              className="transition-colors hover:text-neutral-200 active:text-neutral-200"
            >
              {item.author_name}
            </Link>
          </dd>
        </div>
        {/* The grid is newest first, which says nothing on its own about whether
            this is from this week or last year. */}
        <div className="flex gap-1">
          <dt className="sr-only">Published</dt>
          <dd>
            <time dateTime={item.created_at}>
              {new Date(item.created_at).toISOString().slice(0, 10)}
            </time>
          </dd>
        </div>
      </dl>

      {item.tags.length > 0 ? (
        <ul className="flex flex-wrap gap-1.5">
          {item.tags.map((tag) => (
            <li key={tag}>
              <Link
                href={filterHref(filters, { tag: [tag] })}
                className="inline-block rounded bg-neutral-900 px-2 py-1 text-xs text-neutral-400 transition-colors hover:text-neutral-200 active:text-neutral-200"
              >
                {tag}
              </Link>
            </li>
          ))}
        </ul>
      ) : null}

      {/* Pushed to the bottom so the one action every card has lines up across a
          row, whatever length the descriptions are. */}
      <div className="mt-auto pt-1">
        <ImportLink shareUrl={`${origin}/i/${item.id}`} />
      </div>
    </article>
  );
}

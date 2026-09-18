import Link from "next/link";
import { ImportLink } from "@/components/ImportLink";
import { ItemCardArt } from "@/components/ItemCardArt";
import { KindIcon } from "@/components/KindIcon";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import type { CardShape } from "@/lib/gallery/cardShapes";
import type { CardTitle } from "@/lib/gallery/cardTitles";
import { itemLabel } from "@/lib/gallery/label";
import type { ModProjectCounts } from "@/lib/gallery/modProjectPreview";
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
/** What a project's card says about it, in the order `modProjectPreview.ts`
 *  counts them: the singular and the plural, since a card sits close enough
 *  to "1 clones" for it to read as a typo rather than a count. A store
 *  nothing changed is left out rather than shown as zero, the way
 *  `ScenarioPreview` (`components/ItemPreview.tsx`) hides an empty stat. */
const PROJECT_COUNT_LABELS: [key: keyof ModProjectCounts, singular: string, plural: string][] = [
  ["unitsTouched", "unit", "units"],
  ["fields", "field", "fields"],
  ["clones", "clone", "clones"],
  ["menuOps", "menu edit", "menu edits"],
  ["disabled", "disabled", "disabled"],
];

/**
 * A project's counts, as one line under its description (issue #324).
 *
 * A project has no picture, so unlike a challenge's galaxy or a blueprint's
 * layout its edits have nowhere to draw in `ItemCardArt`'s picture slot. This
 * is text instead, the game already being said by the generic `dl` below.
 */
function ProjectCounts({ counts }: { counts: ModProjectCounts }) {
  const shown = PROJECT_COUNT_LABELS.filter(([key]) => counts[key] > 0);
  if (shown.length === 0) return null;
  return (
    <p className="text-xs text-neutral-400">
      {shown
        .map(([key, singular, plural]) => {
          const n = counts[key];
          return `${n} ${n === 1 ? singular : plural}`;
        })
        .join(" · ")}
    </p>
  );
}

export function ItemCard({
  item,
  filters,
  origin,
  picture,
  shape,
  counts,
  title,
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
  /** This card's galaxy, run or layout, rebuilt for the whole page at once
   *  (`lib/gallery/cardShapes.ts`). Undefined for a kind that has no drawing
   *  and for one that could not be rebuilt. Drawn by `ItemCardArt`: a
   *  challenge's galaxy or run (#309), and a blueprint's layout (#310). */
  shape?: CardShape;
  /** This card's edit counts, when `item.kind` is `mod-project` - the same
   *  page level batching as `shape` (`lib/gallery/cardCounts.ts`). Undefined
   *  for every other kind, and for a project row nothing was looked up for. */
  counts?: ModProjectCounts;
  /** What to show in place of `item.title`, worked out for the whole page at
   *  once against every other title on it (`lib/gallery/cardTitles.ts`,
   *  issue #311). Falls back to `item.title` with no tail when a caller has
   *  not computed one, which is only ever a page that has no duplicate to
   *  worry about in the first place. */
  title?: CardTitle;
}) {
  const cardTitle = title ?? { title: item.title, tail: null };
  return (
    <article className="flex h-full flex-col gap-3 rounded-md border border-neutral-800 bg-card p-5">
      {/* Over the picture rather than beside the title, so a featured card is
          the same shape as every other one and the grid still lines up. A card
          at the top of the page for a reason nobody can see reads as the newest
          one (#395). */}
      <div className="relative">
        <ItemCardArt item={item} picture={picture} shape={shape} />
        {item.featured_at ? (
          <span className="absolute left-2 top-2 rounded border border-neutral-700 bg-black/80 px-2 py-1 text-xs text-neutral-100">
            Featured
          </span>
        ) : null}
      </div>
      <div className="flex items-start justify-between gap-3">
        {/* A title is one field of free text and nothing stops it being a single
            120 character word, which without this drags the whole grid sideways. */}
        <h2 className="min-w-0 break-words text-base font-medium leading-snug">
          <Link href={`/item/${item.id}`} className="hover:underline active:underline">
            {cardTitle.title}
            {/* Only present when a twin on this page shares the title above, so
                a screen reader hears the same distinction a sighted reader sees,
                rather than two links both announced as the same text (#311). */}
            {cardTitle.tail ? (
              <span className="text-neutral-500"> #{cardTitle.tail}</span>
            ) : null}
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

      {counts ? <ProjectCounts counts={counts} /> : null}

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

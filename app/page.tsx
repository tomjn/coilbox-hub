import Link from "next/link";
import { CoilLogo } from "@/components/CoilLogo";
import { HubArt } from "@/components/HubArt";
import { ItemCard } from "@/components/ItemCard";
import { COILBOX_URL } from "@/lib/coilbox";
import { cardCountsFromEntries } from "@/lib/gallery/cardCounts";
import { cardPicturesFromEntries } from "@/lib/gallery/cardPictures";
import { cardShapesFromEntries } from "@/lib/gallery/cardShapes";
import { cardTitles } from "@/lib/gallery/cardTitles";
import { featuredItems, newestItems } from "@/lib/gallery/cached";
import { kindsPlural } from "@/lib/gallery/label";
import { requestOrigin } from "@/lib/gallery/origin";
import { parseFilters } from "@/lib/gallery/query";

// Scales the shape opacities in `HubArt` down from their PR #61 panel
// tuning: sitting behind the hero text at full viewport size, that tuning
// is too strong.
const BACKDROP_STRENGTH = 0.11;

// Shared by the two secondary buttons, so the third one added beside the
// gallery button cannot drift from the one that was already there.
const outlineButton =
  "rounded-md border border-neutral-800 px-5 py-2.5 text-sm font-medium text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white";

export default async function Home() {
  const origin = await requestOrigin();
  const {
    items,
    pictures: entries,
    shapes: shapeEntries,
    counts: countEntries,
  } = await newestItems();
  const pictures = cardPicturesFromEntries(entries);
  const shapes = cardShapesFromEntries(shapeEntries);
  const counts = cardCountsFromEntries(countEntries);
  // Computed over these six rather than globally: a duplicate two pages
  // into the gallery is not visible here, so it earns no tail here (#311).
  const titles = cardTitles(items);
  const filters = parseFilters({});

  // A row of its own below "Newest" rather than folded into it (#399): the
  // heading above the first row promises the newest six, and mixing a
  // moderator's pick into that row would make the promise false. Read after
  // `newestItems()` rather than alongside it with `Promise.all`, since
  // neither is on the request's critical path: both are `"use cache"` reads
  // that only block the render once, whichever order they run in.
  const {
    items: featured,
    pictures: featuredEntries,
    shapes: featuredShapeEntries,
    counts: featuredCountEntries,
  } = await featuredItems();
  const featuredPictures = cardPicturesFromEntries(featuredEntries);
  const featuredShapes = cardShapesFromEntries(featuredShapeEntries);
  const featuredCounts = cardCountsFromEntries(featuredCountEntries);
  const featuredTitles = cardTitles(featured);

  return (
    <main className="relative mx-auto flex w-full max-w-5xl flex-1 flex-col gap-12 px-6 py-16">
      {/* Fixed to the viewport rather than the page, so it stays put as the
          page scrolls instead of moving with the content beneath it.
          `inset-0` covers the whole viewport, which also means it never
          needs to break out of `max-w-5xl` the way an in-flow backdrop
          would. The mask is the fade: full strength at the bottom of the
          viewport, nothing at the top. `pointer-events-none` and
          `aria-hidden` keep it out of the way of the hero buttons and the
          page's semantics. */}
      <div
        aria-hidden
        className="pointer-events-none fixed inset-0 z-0 overflow-hidden"
        style={{
          maskImage: "linear-gradient(to top, black, transparent)",
          WebkitMaskImage: "linear-gradient(to top, black, transparent)",
        }}
      >
        <HubArt className="h-full w-full" strength={BACKDROP_STRENGTH} />
      </div>

      <div className="relative z-10 flex flex-col items-center gap-6 text-center">
        <CoilLogo className="w-20" />
        <div className="flex flex-col gap-3">
          <h1 className="text-4xl font-semibold tracking-tight sm:text-5xl">
            Coilbox Hub
          </h1>
          <p className="mx-auto max-w-md text-balance text-neutral-400">
            {/* Built from the kinds the gallery carries, so the first line a
                visitor reads cannot fall behind them (tomjn/coilbox#1502). */}
            {kindsPlural()} made by other players. Importing needs no account.
          </p>
        </div>
        <div className="flex flex-wrap justify-center gap-3">
          <Link
            href="/gallery"
            className="rounded-md bg-neutral-100 px-5 py-2.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-white active:bg-neutral-300"
          >
            Browse the gallery
          </Link>
          <Link href="/publish" className={outlineButton}>
            Publish something
          </Link>
          <a
            href={COILBOX_URL}
            target="_blank"
            rel="noreferrer"
            className={outlineButton}
          >
            Get Coilbox
          </a>
        </div>
      </div>

      {items.length > 0 ? (
        <section className="relative z-10 flex flex-col gap-4">
          <div className="flex items-baseline justify-between">
            <h2 className="text-sm uppercase tracking-wide text-neutral-500">
              Newest
            </h2>
            <Link
              href="/gallery"
              className="text-sm text-neutral-400 transition-colors hover:text-white active:text-white"
            >
              See all
            </Link>
          </div>
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {items.map((item) => (
              <li key={item.id}>
                <ItemCard
                  item={item}
                  filters={filters}
                  origin={origin}
                  picture={item.map_name ? pictures.get(item.map_name) : undefined}
                  shape={shapes.get(item.id)}
                  counts={counts.get(item.id)}
                  title={titles.get(item.id)}
                />
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {/* Absent rather than an empty heading when nobody has featured
          anything yet (#399): a heading over nothing tells a reader the hub
          is missing content instead of telling them a moderator has not
          picked anything to lead with. */}
      {featured.length > 0 ? (
        <section className="relative z-10 flex flex-col gap-4">
          <div className="flex items-baseline justify-between">
            <h2 className="text-sm uppercase tracking-wide text-neutral-500">
              Featured
            </h2>
            {/* Straight to the gallery's own default order, not a query
                string of our own: `applyOrder` in lib/gallery/query.ts
                already puts every featured item first under whichever sort
                a reader picks, so `/gallery` already is "more of these" and
                a bespoke filter would only repeat what the listing already
                does. */}
            <Link
              href="/gallery"
              className="text-sm text-neutral-400 transition-colors hover:text-white active:text-white"
            >
              See all
            </Link>
          </div>
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {featured.map((item) => (
              <li key={item.id}>
                <ItemCard
                  item={item}
                  filters={filters}
                  origin={origin}
                  picture={
                    item.map_name ? featuredPictures.get(item.map_name) : undefined
                  }
                  shape={featuredShapes.get(item.id)}
                  counts={featuredCounts.get(item.id)}
                  title={featuredTitles.get(item.id)}
                />
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </main>
  );
}

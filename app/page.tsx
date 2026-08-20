import Link from "next/link";
import { CoilLogo } from "@/components/CoilLogo";
import { HubArt } from "@/components/HubArt";
import { ItemCard } from "@/components/ItemCard";
import { COILBOX_URL } from "@/lib/coilbox";
import { newestItems } from "@/lib/gallery/cached";
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
  const items = await newestItems();
  const filters = parseFilters({});

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
          <ul className="grid gap-4 sm:grid-cols-2">
            {items.map((item) => (
              <li key={item.id}>
                <ItemCard item={item} filters={filters} origin={origin} />
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </main>
  );
}

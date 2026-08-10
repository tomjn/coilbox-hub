import Link from "next/link";
import { CoilLogo } from "@/components/CoilLogo";
import { HubArt } from "@/components/HubArt";
import { ItemCard } from "@/components/ItemCard";
import { requestOrigin } from "@/lib/gallery/origin";
import {
  ITEM_SUMMARY_COLUMNS,
  type ItemSummary,
  parseFilters,
} from "@/lib/gallery/query";
import { createClient } from "@/lib/supabase/server";

export default async function Home() {
  const origin = await requestOrigin();
  const supabase = await createClient();

  const { data } = await supabase
    .from("item")
    .select(ITEM_SUMMARY_COLUMNS)
    .order("created_at", { ascending: false })
    .limit(4);

  const items = (data ?? []) as unknown as ItemSummary[];
  const filters = parseFilters({});

  return (
    <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-12 px-6 py-16">
      <div className="flex flex-col items-center gap-6 text-center">
        <CoilLogo className="w-20" />
        <div className="flex flex-col gap-3">
          <h1 className="text-4xl font-semibold tracking-tight sm:text-5xl">
            Coilbox Hub
          </h1>
          <p className="mx-auto max-w-md text-balance text-neutral-400">
            Presets, challenges, setup packs and scenarios made by other players.
            Importing needs no account.
          </p>
        </div>
        <div className="flex gap-3">
          <Link
            href="/gallery"
            className="rounded-md bg-neutral-100 px-5 py-2.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-white"
          >
            Browse the gallery
          </Link>
          <Link
            href="/publish"
            className="rounded-md border border-neutral-800 px-5 py-2.5 text-sm font-medium text-neutral-300 transition-colors hover:border-neutral-600 hover:text-white"
          >
            Publish something
          </Link>
        </div>
      </div>

      <div className="mx-auto w-full max-w-2xl overflow-hidden rounded-lg border border-neutral-800">
        <HubArt className="w-full" />
      </div>

      {items.length > 0 ? (
        <section className="flex flex-col gap-4">
          <div className="flex items-baseline justify-between">
            <h2 className="text-sm uppercase tracking-wide text-neutral-500">
              Newest
            </h2>
            <Link
              href="/gallery"
              className="text-sm text-neutral-400 transition-colors hover:text-white"
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

import type { Metadata } from "next";
import Link from "next/link";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { hub } from "@/components/art/drawings";
import { BusyForm } from "@/components/BusyForm";
import { ItemCard } from "@/components/ItemCard";
import { LinkPending } from "@/components/LinkPending";
import { GALLERY_KINDS } from "@/lib/container";
import { kindLabelPlural, kindsPlural } from "@/lib/gallery/label";
import { requestOrigin } from "@/lib/gallery/origin";
import {
  applyFilters,
  fetchPage,
  filterHref,
  ITEM_SUMMARY_COLUMNS,
  type ItemSummary,
  PAGE_SIZE,
  parseFilters,
} from "@/lib/gallery/query";
import { createClient } from "@/lib/supabase/server";

export const metadata: Metadata = {
  title: "Gallery - Coilbox Hub",
  // Built from the kinds the chips below offer, so it cannot say four when
  // there are five (tomjn/coilbox#1502).
  description: `${kindsPlural()} shared by other players.`,
};

// Lower than the landing page's: this page is filter chips and a grid of
// cards rather than a sparse hero, so the same `hub` drawing needs to sit
// further back to read as atmosphere rather than noise behind real content.
const BACKDROP_STRENGTH = 0.07;

export default async function Gallery({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const filters = parseFilters(await searchParams);
  const origin = await requestOrigin();
  const supabase = await createClient();

  const query = applyFilters(
    supabase
      .from("item")
      .select(ITEM_SUMMARY_COLUMNS, { count: "exact" })
      .order("created_at", { ascending: false })
      .range((filters.page - 1) * PAGE_SIZE, filters.page * PAGE_SIZE - 1),
    filters,
  );

  const countQuery = async () => {
    const { count, error } = await applyFilters(
      supabase.from("item").select(ITEM_SUMMARY_COLUMNS, { count: "exact" }).range(0, 0),
      filters,
    );
    return { count, error };
  };
  const { data, count, error } = await fetchPage(() => query, countQuery);
  const items = data as unknown as ItemSummary[];
  const total = count;
  const lastPage = Math.max(1, Math.ceil(total / PAGE_SIZE));

  // Filter options come from the rows themselves. At this size that is one small
  // query, and it is honest: an option only appears when something is behind it.
  // It will need a view or a materialised list long before it needs paging.
  // The game facet is built from game_key, not game_name: game_name can hold
  // a version-carrying archive name unique to one row (issue #50), and a
  // chip built from that would offer a filter that matches nothing else.
  const { data: facetRows } = await supabase
    .from("item")
    .select("game_key,map_name")
    .limit(1000);
  const games = distinct(facetRows?.map((row) => row.game_key));
  const maps = distinct(facetRows?.map((row) => row.map_name));

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={hub} strength={BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <div className="flex flex-col gap-2">
          <h1 className="text-3xl font-semibold tracking-tight">Gallery</h1>
          <p className="text-neutral-400">
            Made by other players. Importing needs no account.
          </p>
        </div>

        <BusyForm className="flex gap-2" action="/gallery">
          {filters.kind ? <input type="hidden" name="kind" value={filters.kind} /> : null}
          {filters.game ? <input type="hidden" name="game" value={filters.game} /> : null}
          {filters.map ? <input type="hidden" name="map" value={filters.map} /> : null}
          {filters.tag ? <input type="hidden" name="tag" value={filters.tag} /> : null}
          {filters.author ? <input type="hidden" name="author" value={filters.author} /> : null}
          <input
            type="search"
            name="q"
            defaultValue={filters.q ?? ""}
            placeholder="Search titles and descriptions"
            className="w-full rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-500 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400"
          />
          <button
            type="submit"
            className="shrink-0 rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white group-aria-busy:cursor-progress group-aria-busy:opacity-60"
          >
            Search
          </button>
        </BusyForm>

        <nav className="flex flex-col gap-3 border-b border-neutral-900 pb-6">
          <FilterRow label="Kind">
            {GALLERY_KINDS.map((kind) => (
              <Chip
                key={kind}
                href={filterHref(filters, {
                  kind: filters.kind === kind ? null : kind,
                })}
                active={filters.kind === kind}
              >
                {kindLabelPlural(kind)}
              </Chip>
            ))}
          </FilterRow>

          {games.length > 1 || filters.game ? (
            <FilterRow label="Game">
              {games.map((game) => (
                <Chip
                  key={game}
                  href={filterHref(filters, {
                    game: filters.game === game ? null : game,
                  })}
                  active={filters.game === game}
                >
                  {game}
                </Chip>
              ))}
            </FilterRow>
          ) : null}

          {maps.length > 1 || filters.map ? (
            <FilterRow label="Map">
              {maps.map((map) => (
                <Chip
                  key={map}
                  href={filterHref(filters, {
                    map: filters.map === map ? null : map,
                  })}
                  active={filters.map === map}
                >
                  {map}
                </Chip>
              ))}
            </FilterRow>
          ) : null}

          {filters.tag ? (
            <FilterRow label="Tag">
              <Chip href={filterHref(filters, { tag: null })} active>
                {filters.tag}
              </Chip>
            </FilterRow>
          ) : null}

          {filters.author ? (
            <FilterRow label="By">
              <Chip href={filterHref(filters, { author: null })} active>
                {filters.author}
              </Chip>
            </FilterRow>
          ) : null}
        </nav>

        {error ? (
          <p className="text-sm text-red-400">
            The gallery could not be read just now. Try again in a moment.
          </p>
        ) : items.length === 0 ? (
          <Empty filtered={Boolean(filters.kind || filters.game || filters.map || filters.tag || filters.author || filters.q)} />
        ) : (
          <>
            <ul className="grid gap-4 sm:grid-cols-2">
              {items.map((item) => (
                <li key={item.id}>
                  <ItemCard item={item} filters={filters} origin={origin} />
                </li>
              ))}
            </ul>

            {lastPage > 1 ? (
              <div className="flex items-center justify-between text-sm text-neutral-500">
                {filters.page > 1 ? (
                  <Link
                    href={filterHref(filters, { page: filters.page - 1 })}
                    className="hover:text-neutral-200 active:text-neutral-200"
                  >
                    <LinkPending>Newer</LinkPending>
                  </Link>
                ) : (
                  <span />
                )}
                <span>
                  Page {filters.page} of {lastPage}
                </span>
                {filters.page < lastPage ? (
                  <Link
                    href={filterHref(filters, { page: filters.page + 1 })}
                    className="hover:text-neutral-200 active:text-neutral-200"
                  >
                    <LinkPending>Older</LinkPending>
                  </Link>
                ) : (
                  <span />
                )}
              </div>
            ) : null}
          </>
        )}
      </div>
    </main>
  );
}

function distinct(values: (string | null)[] | undefined): string[] {
  return [...new Set((values ?? []).filter((v): v is string => Boolean(v)))]
    .sort()
    .slice(0, 20);
}

function FilterRow({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-wrap items-baseline gap-2">
      <span className="w-12 shrink-0 text-xs uppercase tracking-wide text-neutral-400">
        {label}
      </span>
      {children}
    </div>
  );
}

function Chip({
  href,
  active,
  children,
}: {
  href: string;
  active: boolean;
  children: React.ReactNode;
}) {
  return (
    <Link
      href={href}
      className={
        active
          ? "rounded-full bg-neutral-100 px-3 py-1 text-xs font-medium text-neutral-900"
          : "rounded-full border border-neutral-800 px-3 py-1 text-xs text-neutral-400 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200"
      }
    >
      {children}
    </Link>
  );
}

function Empty({ filtered }: { filtered: boolean }) {
  return (
    <div className="rounded-md border border-neutral-800 bg-neutral-950 p-8 text-center">
      <p className="text-sm text-neutral-400">
        {filtered
          ? "Nothing matches that yet."
          : "Nothing has been published yet."}
      </p>
      <Link
        href={filtered ? "/gallery" : "/publish"}
        className="mt-3 inline-block text-sm text-neutral-300 underline-offset-4 hover:underline active:underline"
      >
        {filtered ? "Clear the filters" : "Publish the first thing"}
      </Link>
    </div>
  );
}

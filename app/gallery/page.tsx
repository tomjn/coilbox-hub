import type { Metadata } from "next";
import Link from "next/link";
import { ItemCard } from "@/components/ItemCard";
import { GALLERY_KINDS } from "@/lib/container";
import { requestOrigin } from "@/lib/gallery/origin";
import {
  filterHref,
  ITEM_SUMMARY_COLUMNS,
  type ItemSummary,
  PAGE_SIZE,
  parseFilters,
} from "@/lib/gallery/query";
import { createClient } from "@/lib/supabase/server";

export const metadata: Metadata = {
  title: "Gallery - Coilbox Hub",
  description:
    "Presets, challenges, setup packs and scenarios shared by other players.",
};

const KIND_LABEL: Record<string, string> = {
  preset: "Presets",
  challenge: "Challenges",
  "setup-pack": "Setup packs",
  scenario: "Scenarios",
};

export default async function Gallery({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const filters = parseFilters(await searchParams);
  const origin = await requestOrigin();
  const supabase = await createClient();

  let query = supabase
    .from("item")
    .select(ITEM_SUMMARY_COLUMNS, { count: "exact" })
    .order("created_at", { ascending: false })
    .range((filters.page - 1) * PAGE_SIZE, filters.page * PAGE_SIZE - 1);

  if (filters.kind) query = query.eq("kind", filters.kind);
  if (filters.game) query = query.eq("game_name", filters.game);
  if (filters.map) query = query.eq("map_name", filters.map);
  if (filters.tag) query = query.contains("tags", [filters.tag]);

  const { data, count, error } = await query;
  const items = (data ?? []) as unknown as ItemSummary[];
  const total = count ?? 0;
  const lastPage = Math.max(1, Math.ceil(total / PAGE_SIZE));

  // Filter options come from the rows themselves. At this size that is one small
  // query, and it is honest: an option only appears when something is behind it.
  // It will need a view or a materialised list long before it needs paging.
  const { data: facetRows } = await supabase
    .from("item")
    .select("game_name,map_name")
    .limit(1000);
  const games = distinct(facetRows?.map((row) => row.game_name));
  const maps = distinct(facetRows?.map((row) => row.map_name));

  return (
    <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-8 px-6 py-12">
      <div className="flex flex-col gap-2">
        <h1 className="text-3xl font-semibold tracking-tight">Gallery</h1>
        <p className="text-neutral-400">
          Made by other players. Importing needs no account.
        </p>
      </div>

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
              {KIND_LABEL[kind]}
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
      </nav>

      {error ? (
        <p className="text-sm text-red-400">
          The gallery could not be read just now. Try again in a moment.
        </p>
      ) : items.length === 0 ? (
        <Empty filtered={Boolean(filters.kind || filters.game || filters.map || filters.tag)} />
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
                  className="hover:text-neutral-200"
                >
                  Newer
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
                  className="hover:text-neutral-200"
                >
                  Older
                </Link>
              ) : (
                <span />
              )}
            </div>
          ) : null}
        </>
      )}
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
      <span className="w-12 shrink-0 text-xs uppercase tracking-wide text-neutral-600">
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
          : "rounded-full border border-neutral-800 px-3 py-1 text-xs text-neutral-400 transition-colors hover:border-neutral-600 hover:text-neutral-200"
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
        className="mt-3 inline-block text-sm text-neutral-300 underline-offset-4 hover:underline"
      >
        {filtered ? "Clear the filters" : "Publish the first thing"}
      </Link>
    </div>
  );
}

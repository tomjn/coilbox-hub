import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { cache } from "react";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { ImportLink } from "@/components/ImportLink";
import { ItemPreview } from "@/components/ItemPreview";
import { KindIcon } from "@/components/KindIcon";
import { MapMinimap } from "@/components/MapMinimap";
import { ReportButton } from "@/components/ReportButton";
import { type PackMap, SetupPackContents } from "@/components/SetupPackContents";
import { itemArt } from "@/lib/gallery/itemArt";
import { itemPictures } from "@/lib/gallery/itemPictures";
import { itemLabel } from "@/lib/gallery/label";
import { requestOrigin } from "@/lib/gallery/origin";
import { startPosNote } from "@/lib/gallery/presetPreview";
import { setupPackMaps } from "@/lib/gallery/setupPackPreview";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";
import { currentUser } from "@/lib/supabase/user";

interface ItemDetail {
  id: string;
  kind: string;
  mode: string | null;
  container: unknown;
  title: string;
  description: string;
  game_name: string | null;
  /** The grouping key `/gallery?game=` filters on (issue #50). Absent when
   * `game_name` is only the exact archive name, since that is not shared by
   * anything else to filter to. */
  game_key: string | null;
  map_name: string | null;
  tags: string[];
  author_name: string;
  created_at: string;
  updated_at: string;
  /** Imports coilbox has pinged back for (issue #51): only ones that started
   * from this page's link, and only since the coilbox release that sends the
   * ping. Nothing before that, and nothing outside a hub link, was ever
   * countable. */
  import_count: number;
}

const DETAIL_COLUMNS =
  "id,kind,mode,container,title,description,game_name,game_key,map_name,tags,author_name,created_at,updated_at,import_count";

/** A withdrawn item is invisible to the read policy, so it arrives here as
 * nothing found without this page knowing about moderation.
 *
 * Once per request: `generateMetadata` and the page both ask, and `cache`
 * makes the second ask a lookup rather than a second read. */
const load = cache(async (id: string): Promise<ItemDetail | null> => {
  const supabase = await createClient();
  const { data } = await supabase
    .from("item")
    .select(DETAIL_COLUMNS)
    .eq("id", id)
    .maybeSingle();
  return (data as ItemDetail | null) ?? null;
});

export async function generateMetadata({
  params,
}: {
  params: Promise<{ id: string }>;
}): Promise<Metadata> {
  const item = await load((await params).id);
  if (!item) return { title: "Not found - Coilbox Hub" };

  // Per item, because a link into a Discord channel is how most people will meet
  // this page and a generic preview wastes the only chance to say what it is.
  const label = itemLabel(item.kind, item.mode);
  const description =
    item.description ||
    [label, item.game_name, item.map_name].filter(Boolean).join(" - ");

  return {
    title: `${item.title} - Coilbox Hub`,
    description,
    openGraph: { title: item.title, description, type: "article" },
  };
}

function Fact({ term, children }: { term: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-xs uppercase tracking-wide text-neutral-400">
        {term}
      </dt>
      <dd className="text-sm text-neutral-100">{children}</dd>
    </div>
  );
}

export default async function Item({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const item = await load(id);
  if (!item) notFound();

  const supabase = await createClient();
  const user = await currentUser();
  const { data: owned } = user
    ? await supabase
        .from("item")
        .select("id,deleted_at")
        .eq("id", id)
        .eq("author_id", user.id)
        .maybeSingle()
    : { data: null };
  const mine = Boolean(owned);
  const withdrawn = Boolean(owned?.deleted_at);

  const origin = await requestOrigin();
  const shareUrl = `${origin}/i/${item.id}`;
  const published = new Date(item.created_at).toISOString().slice(0, 10);
  const { drawing, strength } = itemArt(item.kind, item.mode);

  // Every map this page names: the one on the row, and a setup pack's own list
  // (issue #176).
  const packMapNames = setupPackMaps(item.container);
  // Every picture in one query: the row's map, a pack's maps, and a buildpic
  // for every distinct unit in a blueprint (issue #109). The secret key is the
  // second client because the map facts come through the licence gate over
  // `public.asset_licence`, which nothing else may read (issue #191).
  const pictures = await itemPictures(supabase, createAdminClient(), item);
  const packMaps: PackMap[] = packMapNames.flatMap((name) => {
    // Present for every name asked for, since a map with nothing stored
    // resolves to the placeholder rather than to nothing. The catalog row is
    // the other way round: most maps have none, and a card without one is the
    // card the pack has always shown.
    const picture = pictures.packMaps.get(name);
    return picture ? [{ name, picture, catalog: pictures.catalog.get(name) ?? null }] : [];
  });
  // An item that names a map always gets the slot. Whether the hub's own
  // minimap or a drawing fills it is `MapMinimap`'s to decide, and one of the
  // two always can. A setup pack is the exception below: it draws its own maps
  // under a heading, one or twenty.
  const minimap = pictures.map ? (
    <MapMinimap
      name={item.map_name ?? ""}
      picture={pictures.map}
      note={startPosNote(item.kind, item.container)}
      catalog={item.map_name ? (pictures.catalog.get(item.map_name) ?? null) : null}
    />
  ) : null;

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={drawing} strength={strength} />
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-8 px-6 py-12">
        <div className="flex flex-col gap-3">
          <Link
            href={`/gallery?kind=${item.kind}`}
            className="flex items-center gap-1.5 self-start rounded border border-neutral-800 px-2 py-1 text-xs text-neutral-300 transition-colors hover:border-neutral-600 hover:text-white"
          >
            <KindIcon kind={item.kind} mode={item.mode} className="w-3.5" />
            {itemLabel(item.kind, item.mode)}
          </Link>
          <h1 className="break-words text-3xl font-semibold tracking-tight">
            {item.title}
          </h1>
          {item.description ? (
            <p className="whitespace-pre-wrap text-neutral-400">
              {item.description}
            </p>
          ) : null}
        </div>

        {item.kind === "setup-pack" ? (
          <SetupPackContents container={item.container} maps={packMaps} />
        ) : minimap ? (
          // Where and who, side by side: the two halves of what a preset is.
          // `empty:hidden` because a kind can render no preview at all, and an
          // empty flex item would still take the row's gap.
          <div className="flex flex-col gap-6 sm:flex-row sm:items-start">
            <div className="w-full shrink-0 sm:w-80">{minimap}</div>
            <div className="min-w-0 flex-1 empty:hidden">
              <ItemPreview
                kind={item.kind}
                container={item.container}
                units={pictures.units}
              />
            </div>
          </div>
        ) : (
          <ItemPreview
            kind={item.kind}
            container={item.container}
            units={pictures.units}
          />
        )}

        <div className="flex flex-col gap-4 rounded-md border border-neutral-800 bg-neutral-950 p-5">
          <ImportLink shareUrl={shareUrl} variant="solid" />
          <div className="flex flex-col gap-1.5">
            <span className="text-xs text-neutral-400">
              Or share this link. It opens in Coilbox and needs no account.
            </span>
            <code className="break-all rounded border border-neutral-800 bg-black px-3 py-2 text-xs text-neutral-400">
              {shareUrl}
            </code>
          </div>
        </div>

        {mine ? (
          <div className="flex items-center justify-between rounded-md border border-neutral-800 bg-neutral-950 px-5 py-3 text-sm">
            <span className="text-neutral-500">
              {withdrawn ? "You have withdrawn this." : "This is yours."}
            </span>
            <Link
              href={`/item/${item.id}/edit`}
              className="text-neutral-300 underline-offset-4 hover:underline"
            >
              Edit or withdraw
            </Link>
          </div>
        ) : null}

        <dl className="grid grid-cols-2 gap-6 border-t border-neutral-900 pt-6 sm:grid-cols-4">
          <Fact term="Published by">
            <Link
              href={`/gallery?author=${encodeURIComponent(item.author_name)}`}
              className="hover:text-white"
            >
              {item.author_name}
            </Link>
          </Fact>
          <Fact term="Published">{published}</Fact>
          {item.import_count > 0 ? (
            // Zero is not shown at all (issue #51): most items sit at zero
            // for a long time, since only coilbox's own release onward can
            // ever send this ping, and a row of "0" reads as unwanted rather
            // than as "not counted yet".
            <Fact term="Imported via hub link">{item.import_count}</Fact>
          ) : null}
          {item.game_name ? (
            <Fact term="Game">
              {item.game_key ? (
                <Link
                  href={`/gallery?game=${encodeURIComponent(item.game_key)}`}
                  className="hover:text-white"
                >
                  {item.game_name}
                </Link>
              ) : (
                // No key to filter by (issue #50): this names the exact build
                // rather than the stable game, so it is shown but not offered
                // as a link that would filter to nothing else.
                item.game_name
              )}
            </Fact>
          ) : null}
          {item.map_name ? (
            <Fact term="Map">
              <Link
                href={`/gallery?map=${encodeURIComponent(item.map_name)}`}
                className="hover:text-white"
              >
                {item.map_name}
              </Link>
            </Fact>
          ) : null}
        </dl>

        {item.tags.length > 0 ? (
          <ul className="flex flex-wrap gap-1.5">
            {item.tags.map((tag) => (
              <li key={tag}>
                <Link
                  href={`/gallery?tag=${encodeURIComponent(tag)}`}
                  className="inline-block rounded bg-neutral-900 px-2 py-1 text-xs text-neutral-400 transition-colors hover:text-neutral-200"
                >
                  {tag}
                </Link>
              </li>
            ))}
          </ul>
        ) : null}
        <ReportButton itemId={item.id} />
      </div>
    </main>
  );
}

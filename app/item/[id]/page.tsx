import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { requestOrigin } from "@/lib/gallery/origin";
import { createClient } from "@/lib/supabase/server";

const KIND_LABEL: Record<string, string> = {
  preset: "Preset",
  challenge: "Challenge",
  "setup-pack": "Setup pack",
  scenario: "Scenario",
};

interface ItemDetail {
  id: string;
  kind: string;
  title: string;
  description: string;
  game_name: string | null;
  map_name: string | null;
  tags: string[];
  author_name: string;
  created_at: string;
  updated_at: string;
}

const DETAIL_COLUMNS =
  "id,kind,title,description,game_name,map_name,tags,author_name,created_at,updated_at";

/** A withdrawn item is invisible to the read policy, so it arrives here as
 * nothing found without this page knowing about moderation. */
async function load(id: string): Promise<ItemDetail | null> {
  const supabase = await createClient();
  const { data } = await supabase
    .from("item")
    .select(DETAIL_COLUMNS)
    .eq("id", id)
    .maybeSingle();
  return (data as ItemDetail | null) ?? null;
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ id: string }>;
}): Promise<Metadata> {
  const item = await load((await params).id);
  if (!item) return { title: "Not found - Coilbox Hub" };

  // Per item, because a link into a Discord channel is how most people will meet
  // this page and a generic preview wastes the only chance to say what it is.
  const label = KIND_LABEL[item.kind] ?? item.kind;
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
      <dt className="text-xs uppercase tracking-wide text-neutral-600">
        {term}
      </dt>
      <dd className="text-sm text-neutral-300">{children}</dd>
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

  const origin = await requestOrigin();
  const shareUrl = `${origin}/i/${item.id}`;
  const importUrl = `coilbox://import?url=${encodeURIComponent(shareUrl)}`;
  const published = new Date(item.created_at).toISOString().slice(0, 10);

  return (
    <main className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-8 px-6 py-12">
      <div className="flex flex-col gap-3">
        <Link
          href={`/gallery?kind=${item.kind}`}
          className="self-start rounded border border-neutral-800 px-2 py-0.5 text-xs text-neutral-400 transition-colors hover:text-neutral-200"
        >
          {KIND_LABEL[item.kind] ?? item.kind}
        </Link>
        <h1 className="text-3xl font-semibold tracking-tight">{item.title}</h1>
        {item.description ? (
          <p className="whitespace-pre-wrap text-neutral-400">
            {item.description}
          </p>
        ) : null}
      </div>

      <div className="flex flex-col gap-4 rounded-md border border-neutral-800 bg-neutral-950 p-5">
        <a
          href={importUrl}
          className="self-start rounded-md bg-neutral-100 px-5 py-2.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-white"
        >
          Import into Coilbox
        </a>
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-neutral-500">
            Or share this link. It opens in Coilbox and needs no account.
          </span>
          <code className="break-all rounded border border-neutral-800 bg-black px-3 py-2 text-xs text-neutral-400">
            {shareUrl}
          </code>
        </div>
      </div>

      <dl className="grid grid-cols-2 gap-6 border-t border-neutral-900 pt-6 sm:grid-cols-4">
        <Fact term="Published by">{item.author_name}</Fact>
        <Fact term="Published">{published}</Fact>
        {item.game_name ? (
          <Fact term="Game">
            <Link
              href={`/gallery?game=${encodeURIComponent(item.game_name)}`}
              className="hover:text-white"
            >
              {item.game_name}
            </Link>
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
                className="rounded bg-neutral-900 px-2 py-0.5 text-xs text-neutral-400 transition-colors hover:text-neutral-200"
              >
                {tag}
              </Link>
            </li>
          ))}
        </ul>
      ) : null}
    </main>
  );
}

import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { cache } from "react";
import { ShownTo } from "@/components/ShownTo";
import { itemPicturesFromEntries, itemPublic } from "@/lib/gallery/itemCached";
import { itemLabel } from "@/lib/gallery/label";
import { ItemBody, ItemFeatureControl, ItemOwnerNote } from "./ItemBody";

/**
 * The item as the public sees it, held between requests.
 *
 * Nothing here reads the request, so the page is the same for everybody and
 * the CDN serves it without running a function. A withdrawn item is nothing
 * found here, and its author and moderators see it at `/item/[id]/withdrawn`,
 * which `not-found.tsx` points them to.
 *
 * Memoised because `generateMetadata` and the page both ask, and without it the
 * second ask walks back into the cached function rather than reusing what the
 * first already has.
 */
const load = cache(async (id: string) => {
  const shared = await itemPublic(id);
  return shared ? { item: shared.item, pictures: itemPicturesFromEntries(shared.pictures) } : null;
});

export async function generateMetadata({
  params,
}: {
  params: Promise<{ id: string }>;
}): Promise<Metadata> {
  const view = await load((await params).id);
  if (!view) return { title: "Not found" };
  const { item } = view;

  // Per item, because a link into a Discord channel is how most people will meet
  // this page and a generic preview wastes the only chance to say what it is.
  const label = itemLabel(item.kind, item.mode);
  const description =
    item.description ||
    [label, item.game_name, item.map_name].filter(Boolean).join(" - ");

  return {
    title: item.title,
    description,
    openGraph: { title: item.title, description, type: "article" },
  };
}

export default async function Item({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const view = await load((await params).id);
  if (!view) notFound();
  const { item, pictures } = view;

  // Drawn in the browser for whoever `hub_nav` says is looking. The actions
  // behind both ask the database again before they write.
  return (
    <ItemBody
      item={item}
      pictures={pictures}
      controls={
        <>
          <ShownTo owner={item.author_id}>
            <ItemOwnerNote id={item.id} withdrawn={false} />
          </ShownTo>
          <ShownTo moderators>
            <ItemFeatureControl item={item} />
          </ShownTo>
        </>
      }
    />
  );
}

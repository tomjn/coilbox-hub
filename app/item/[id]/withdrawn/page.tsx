import type { Metadata } from "next";
import { notFound, redirect } from "next/navigation";
import { Suspense } from "react";
import { DETAIL_COLUMNS, type ItemDetail } from "@/lib/gallery/itemCached";
import { itemPictures } from "@/lib/gallery/itemPictures";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";
import { currentUser, isModerator } from "@/lib/supabase/user";
import { ItemBody, ItemFeatureControl, ItemOwnerNote } from "../ItemBody";

export const metadata: Metadata = {
  title: "Withdrawn",
  robots: { index: false },
};

/**
 * A withdrawn item, for its author and for moderators.
 *
 * The read policy hides a withdrawn item from everybody else, so this reads it
 * with the visitor's own session, per request. That is why it is a page of its
 * own: `/item/[id]` stays the same for everybody and the CDN can serve it.
 * A live item has nothing to show here and goes back to its page.
 */
export default function Withdrawn({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense>
      <WithdrawnItem params={params} />
    </Suspense>
  );
}

async function WithdrawnItem({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const user = await currentUser();
  if (!user) notFound();

  const supabase = await createClient();
  const [{ data }, moderator] = await Promise.all([
    supabase.from("item").select(`${DETAIL_COLUMNS},deleted_at`).eq("id", id).maybeSingle(),
    isModerator(),
  ]);
  const item = data as (ItemDetail & { deleted_at: string | null }) | null;
  if (!item) notFound();
  if (!item.deleted_at) redirect(`/item/${id}`);

  const mine = item.author_id === user.id;
  const pictures = await itemPictures(supabase, createAdminClient(), item);

  return (
    <ItemBody
      item={item}
      pictures={pictures}
      controls={
        <>
          {mine ? <ItemOwnerNote id={item.id} withdrawn /> : null}
          {moderator ? <ItemFeatureControl item={item} /> : null}
        </>
      }
    />
  );
}

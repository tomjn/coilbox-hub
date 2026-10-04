import type { Metadata } from "next";
import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { itemPublic } from "@/lib/gallery/itemCached";
import { createClient } from "@/lib/supabase/server";
import { setWithdrawn } from "./actions";
import { EditForm } from "./EditForm";
import { Button } from "@/components/Button";

/* The public cached read, so this adds no session check and no request time read
   before the page runs. A withdrawn item is not in it, and gets a plain title. */
export async function generateMetadata({
  params,
}: {
  params: Promise<{ id: string }>;
}): Promise<Metadata> {
  const shared = await itemPublic((await params).id);
  return { title: shared ? `Edit ${shared.item.title}` : "Edit item" };
}

export default async function EditItem({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const supabase = await createClient();

  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) redirect(`/publish`);

  // The read policy lets an author see their own withdrawn items, which is what
  // makes withdrawing reversible rather than final.
  const { data } = await supabase
    .from("item")
    .select("id,title,description,tags,author_id,deleted_at")
    .eq("id", id)
    .maybeSingle();

  if (!data) notFound();
  // Ownership is enforced by the update policy regardless. This only avoids
  // showing somebody a form that would refuse to save.
  if (data.author_id !== user.id) notFound();

  const withdrawn = Boolean(data.deleted_at);

  return (
    <main id="main-content" tabIndex={-1} className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-8 px-6 py-12">
      <div className="flex flex-col gap-2">
        <Link
          href={`/item/${id}`}
          className="self-start text-sm text-neutral-400 transition-colors hover:text-neutral-300 active:text-neutral-300"
        >
          Back to the item
        </Link>
        <h1 className="text-3xl font-semibold tracking-tight">Edit</h1>
        <p className="text-sm text-neutral-400">
          The words around it, not the thing itself. Sharing a changed version
          means publishing it again, so the link people already have keeps
          meaning what it meant.
        </p>
      </div>

      <EditForm
        id={data.id}
        title={data.title}
        description={data.description}
        tags={data.tags}
      />

      <div className="flex flex-col gap-3 border-t border-neutral-900 pt-6">
        <h2 className="text-sm font-medium">
          {withdrawn ? "Withdrawn" : "Withdraw"}
        </h2>
        <p className="text-sm text-neutral-400">
          {withdrawn
            ? "Nobody else can see this and its import link returns nothing. You can put it back."
            : "It stops appearing and its import link stops working. Nothing is destroyed and you can put it back."}
        </p>
        <form action={setWithdrawn}>
          <input type="hidden" name="id" value={id} />
          <input
            type="hidden"
            name="withdrawn"
            value={withdrawn ? "false" : "true"}
          />
          <Button
            type="submit"
          >
            {withdrawn ? "Put it back" : "Withdraw it"}
          </Button>
        </form>
      </div>
    </main>
  );
}

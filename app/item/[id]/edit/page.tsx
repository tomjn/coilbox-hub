import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";
import { setWithdrawn } from "./actions";
import { EditForm } from "./EditForm";

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
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-8 px-6 py-12">
      <div className="flex flex-col gap-2">
        <Link
          href={`/item/${id}`}
          className="self-start text-sm text-neutral-500 transition-colors hover:text-neutral-300 active:text-neutral-300"
        >
          Back to the item
        </Link>
        <h1 className="text-3xl font-semibold tracking-tight">Edit</h1>
        <p className="text-sm text-neutral-500">
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
        <p className="text-sm text-neutral-500">
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
          <button
            type="submit"
            className="rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
          >
            {withdrawn ? "Put it back" : "Withdraw it"}
          </button>
        </form>
      </div>
    </main>
  );
}

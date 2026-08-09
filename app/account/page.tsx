import Link from "next/link";
import { redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";
import { deleteAccount } from "./actions";

export default async function Account() {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) redirect("/publish");

  const { count } = await supabase
    .from("item")
    .select("id", { count: "exact", head: true })
    .eq("author_id", user.id);

  const published = count ?? 0;
  const name =
    (user.user_metadata?.full_name as string) ??
    (user.user_metadata?.name as string) ??
    "you";

  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-8 px-6 py-12">
      <div className="flex flex-col gap-2">
        <h1 className="text-3xl font-semibold tracking-tight">Your account</h1>
        <p className="text-neutral-400">
          Signed in as {name}, with {published}{" "}
          {published === 1 ? "thing" : "things"} published.
        </p>
      </div>

      <div className="flex flex-col gap-3">
        <Link
          href="/gallery"
          className="text-sm text-neutral-300 underline-offset-4 hover:underline"
        >
          Everything you have published
        </Link>
        <form action="/auth/signout" method="post">
          <button
            type="submit"
            className="text-sm text-neutral-400 transition-colors hover:text-white"
          >
            Sign out
          </button>
        </form>
      </div>

      <div className="flex flex-col gap-3 rounded-md border border-red-950 bg-neutral-950 p-5">
        <h2 className="text-sm font-medium">Delete your account</h2>
        <p className="text-sm text-neutral-400">
          This removes your account and everything you have published, including
          anything you had already withdrawn. Import links for those items stop
          working. It cannot be undone, and it is not the same as withdrawing
          something, which is reversible.
        </p>
        {published > 0 ? (
          <p className="text-sm text-neutral-500">
            {published} {published === 1 ? "item" : "items"} will go with it.
          </p>
        ) : null}
        <form action={deleteAccount}>
          <button
            type="submit"
            className="rounded-md border border-red-900 px-4 py-2 text-sm text-red-300 transition-colors hover:border-red-700 hover:text-red-200"
          >
            Delete everything
          </button>
        </form>
      </div>
    </main>
  );
}

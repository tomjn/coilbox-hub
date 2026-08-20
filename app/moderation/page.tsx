import Link from "next/link";
import { notFound } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { ModerationNav } from "@/components/ModerationNav";
import { createClient } from "@/lib/supabase/server";
import { actOnReport } from "./actions";

// One moderator, a handful of reports: this page never gets as dense as the
// gallery, so it can sit a little stronger than that page's strength.
const BACKDROP_STRENGTH = 0.08;

/**
 * One moderator to begin with, so this is a list with two buttons rather than a
 * workflow. Anything more elaborate would be building for a volume that does not
 * exist yet.
 */
export default async function Moderation() {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  // Not a 403, because whether this page exists is not something a stranger needs
  // to learn.
  if (!allowed) notFound();

  const { data: reports } = await supabase
    .from("report")
    .select("id,item_id,reason,created_at,item(id,title,kind,deleted_at)")
    .is("handled_at", null)
    .order("created_at", { ascending: false })
    .limit(100);

  const open = reports ?? [];

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      {/* The site header has one moderation entry, so every page behind that
          check has to offer the rest: the contact sheet (issue #114), the
          catalog (issue #193) and the meters (issue #113). */}
      <ModerationNav current="reports" />
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <h1 className="text-3xl font-semibold tracking-tight">Reports</h1>

        {open.length === 0 ? (
          <p className="rounded-md border border-neutral-800 bg-neutral-950 p-6 text-sm text-neutral-400">
            Nothing waiting.
          </p>
        ) : (
          <ul className="flex flex-col gap-4">
            {open.map((r) => {
              const item = r.item as unknown as {
                id: string;
                title: string;
                deleted_at: string | null;
              } | null;
              return (
                <li
                  key={r.id}
                  className="flex flex-col gap-3 rounded-md border border-neutral-800 bg-neutral-950 p-5"
                >
                  <div className="flex items-baseline justify-between gap-3">
                    <Link
                      href={`/item/${item?.id}`}
                      className="text-base font-medium hover:underline active:underline"
                    >
                      {item?.title ?? "A deleted item"}
                    </Link>
                    <span className="text-xs text-neutral-600">
                      {new Date(r.created_at).toISOString().slice(0, 10)}
                    </span>
                  </div>
                  <p className="whitespace-pre-wrap text-sm text-neutral-400">
                    {r.reason}
                  </p>
                  <div className="flex gap-3">
                    {item?.deleted_at ? null : (
                      <form action={actOnReport}>
                        <input type="hidden" name="report_id" value={r.id} />
                        <input
                          type="hidden"
                          name="item_id"
                          value={r.item_id}
                        />
                        <input type="hidden" name="withdraw" value="true" />
                        <button
                          type="submit"
                          className="rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
                        >
                          Withdraw it
                        </button>
                      </form>
                    )}
                    <form action={actOnReport}>
                      <input type="hidden" name="report_id" value={r.id} />
                      <input type="hidden" name="item_id" value={r.item_id} />
                      <button
                        type="submit"
                        className="rounded-md px-4 py-2 text-sm text-neutral-500 transition-colors hover:text-neutral-300 active:text-neutral-300"
                      >
                        Leave it
                      </button>
                    </form>
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </main>
  );
}

import type { Metadata } from "next";
import Link from "next/link";
import { refuseModeration } from "@/lib/access/secondFactor";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { DateTime } from "@/components/DateTime";
import { ModerationNav } from "@/components/ModerationNav";
import { VisibilityToggleForm } from "@/components/VisibilityToggleForm";
import { setItemFeatured } from "@/app/item/actions";
import { createClient } from "@/lib/supabase/server";
import { mapReportKindLabel } from "@/lib/maps/report";
import { mapTitle } from "@/lib/maps/labels";
import { actOnReport } from "./actions";
import { Button, buttonClass } from "@/components/Button";

export const metadata: Metadata = {
  title: "Reports - Moderation",
};

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
  if (!allowed) await refuseModeration("/moderation");

  // The queue, and the short answer to "what is featured right now". Featuring
  // is done from an item's own page, which is where a moderator is standing
  // when they decide, so what is missing without this is the way back: an item
  // featured last month is not one anybody would think to open again.
  const [{ data: reports }, { data: featured }] = await Promise.all([
    supabase
      .from("report")
      .select(
        "id,item_id,map_id,kind,reason,created_at,item(id,title,kind,deleted_at),map(slug,map_name,display_name)",
      )
      .is("handled_at", null)
      .order("created_at", { ascending: false })
      .limit(100),
    supabase
      .from("item")
      .select("id,title,featured_at")
      .not("featured_at", "is", null)
      .order("featured_at", { ascending: false }),
  ]);

  const open = reports ?? [];
  const featuredRows = (featured ?? []) as unknown as {
    id: string;
    title: string;
  }[];

  return (
    <main id="main-content" tabIndex={-1} className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      {/* The site header has one moderation entry, so every page behind that
          check has to offer the rest: the contact sheet (issue #114), the
          catalog (issue #193) and the meters (issue #113). */}
      <ModerationNav current="reports" />
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <h1 className="text-3xl font-semibold tracking-tight">Reports</h1>

        {open.length === 0 ? (
          <p className="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">
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
              const map = r.map as unknown as {
                slug: string;
                map_name: string;
                display_name: string | null;
              } | null;
              if (r.map_id) {
                return (
                  <li
                    key={r.id}
                    className="flex flex-col gap-3 rounded-md border border-neutral-800 bg-card p-5"
                  >
                    <div className="flex items-baseline justify-between gap-3">
                      {map ? (
                        <Link
                          href={`/map/${map.slug}`}
                          className="break-words text-base font-medium hover:underline active:underline"
                        >
                          {mapTitle(map.display_name, map.map_name)}
                        </Link>
                      ) : (
                        <span className="text-base font-medium">A deleted map</span>
                      )}
                      <span className="text-xs text-neutral-400">
                        <DateTime value={r.created_at} />
                      </span>
                    </div>
                    <p className="text-xs uppercase tracking-wide text-neutral-400">
                      Map: {mapReportKindLabel(r.kind)}
                    </p>
                    <p className="whitespace-pre-wrap text-sm text-neutral-400">
                      {r.reason}
                    </p>
                    <form action={actOnReport}>
                      <input type="hidden" name="report_id" value={r.id} />
                      <Button type="submit">Mark handled</Button>
                    </form>
                  </li>
                );
              }
              return (
                <li
                  key={r.id}
                  className="flex flex-col gap-3 rounded-md border border-neutral-800 bg-card p-5"
                >
                  <div className="flex items-baseline justify-between gap-3">
                    <Link
                      href={item?.deleted_at ? `/item/${item.id}/withdrawn` : `/item/${item?.id}`}
                      className="text-base font-medium hover:underline active:underline"
                    >
                      {item?.title ?? "A deleted item"}
                    </Link>
                    <span className="text-xs text-neutral-400">
                      <DateTime value={r.created_at} />
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
                        <Button
                          type="submit"
                        >
                          Withdraw it
                        </Button>
                      </form>
                    )}
                    <form action={actOnReport}>
                      <input type="hidden" name="report_id" value={r.id} />
                      <input type="hidden" name="item_id" value={r.item_id} />
                      <button
                        type="submit"
                        className="rounded-md px-4 py-2 text-sm text-neutral-400 transition-colors hover:text-neutral-300 active:text-neutral-300"
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

        <section
          className="flex flex-col gap-3 border-t border-neutral-900 pt-6"
          aria-labelledby="mod-featured"
        >
          <h2
            id="mod-featured"
            className="text-sm uppercase tracking-wide text-neutral-400"
          >
            Featured
          </h2>
          <p className="text-sm text-neutral-400">
            A featured item sits above the rest of the gallery, whatever the
            reader has sorted or filtered by. Feature one from its own page.
          </p>

          {featuredRows.length > 0 ? (
            <ul className="flex flex-col gap-1.5">
              {featuredRows.map((row) => (
                <li
                  key={row.id}
                  className="flex items-center justify-between gap-3 text-sm"
                >
                  <Link
                    href={`/item/${row.id}`}
                    className="min-w-0 break-words text-neutral-300 underline-offset-4 hover:underline active:underline"
                  >
                    {row.title}
                  </Link>
                  <VisibilityToggleForm
                    action={setItemFeatured}
                    fields={{ id: row.id, featured: "false" }}
                    label="Unfeature"
                    pendingLabel="Removing…"
                    buttonClassName={buttonClass("quiet", { size: "sm" })}
                  />
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-neutral-400">
              Nothing is featured right now.
            </p>
          )}
        </section>
      </div>
    </main>
  );
}

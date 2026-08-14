import Link from "next/link";
import { notFound } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import {
  formatBytes,
  headroom,
  METER_ALERT_FRACTION,
  type Meter,
  fetchMeters,
} from "@/lib/assets/meters";
import { fetchOrphans } from "@/lib/assets/orphan";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";

/**
 * The meters, as a page (issue #113).
 *
 * A page rather than a job because the issue asks for a dashboard glance. The
 * daily sweep prints the same numbers into an Actions log and fails when one of
 * them runs out of room, which is the alert. This is the thing to open when you
 * want to know where the hub stands without waiting for it to go wrong.
 *
 * Both read `lib/assets/meters.ts`, so the page cannot drift from what the job
 * alerts on.
 *
 * ## Why every number wears its basis
 *
 * Two of the six meters are not measurable from here at all. Vercel publishes no
 * API for what a Hobby project has used, so blob data transfer and fast data
 * transfer are dashboard readings and this page says so instead of showing a
 * plausible looking zero. A meter reporting a stale constant as if it were live
 * is worse than no meter, because somebody would trust it.
 *
 * ## Behind `is_moderator()`
 *
 * The only capability that fits. #101 has three and none of them is "may look at
 * the bill", and inventing a fourth for one page would be a migration against a
 * live database to gate a list of byte counts. `notFound()` rather than a 403,
 * matching the other two gated pages: whether this exists is not something a
 * stranger needs to learn.
 *
 * The numbers themselves are read with the secret key, because they count
 * pending and rejected rows that `asset_read_approved` hides and read
 * `public.asset_orphan`, which nothing else holds select on.
 */

// The same strength as the reports page. This is a short page of text, so a
// backdrop can sit a little stronger than it does behind the contact sheet.
const BACKDROP_STRENGTH = 0.08;

const CARD = "rounded-md border border-neutral-800 bg-neutral-950 p-5";

/** What the three bases mean, in a phrase each, so the label is legible without
 *  reading the note underneath it. */
const BASIS_LABEL = {
  counted: "counted from the database",
  estimated: "estimated from the database",
  dashboard: "dashboard only",
} as const;

function used(meter: Meter): string {
  if (meter.used === null) return "no figure";
  return meter.unit === "bytes" ? formatBytes(meter.used) : `${meter.used}`;
}

function allowance(meter: Meter): string {
  return meter.unit === "bytes"
    ? formatBytes(meter.allowance)
    : `${meter.allowance} ${meter.unit}`;
}

export default async function Ops() {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  if (!allowed) notFound();

  const admin = createAdminClient();
  const [report, orphans] = await Promise.all([fetchMeters(admin), fetchOrphans(admin)]);

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-8 px-6 py-12">
        <div className="flex flex-wrap items-baseline justify-between gap-3">
          <h1 className="text-3xl font-semibold tracking-tight">Allowances</h1>
          <Link
            href="/moderation"
            className="text-sm text-neutral-500 transition-colors hover:text-neutral-300"
          >
            Moderation
          </Link>
        </div>

        <p className="text-sm text-neutral-400">
          Read {new Date(report.at).toISOString().replace("T", " ").slice(0, 16)} UTC. Going
          over 2,000 Blob advanced operations in a month removes Blob access for 30 days and
          cannot be paid through, so that is the one to watch. The daily sweep fails on
          purpose once a counted meter passes {Math.round(METER_ALERT_FRACTION * 100)}%.
        </p>

        <section className="flex flex-col gap-4">
          <h2 className="text-xl font-semibold tracking-tight">Meters</h2>
          <ul className="flex flex-col gap-4">
            {report.meters.map((meter) => {
              const full = headroom(meter);
              return (
                <li key={meter.name} className={CARD}>
                  <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h3 className="text-base font-medium">{meter.name}</h3>
                    <span className="text-xs text-neutral-600">
                      {BASIS_LABEL[meter.basis]}
                    </span>
                  </div>
                  <p className="mt-2 text-sm text-neutral-300">
                    {used(meter)} of {allowance(meter)}
                    {full === null ? null : ` (${Math.round(full * 100)}%)`}
                  </p>
                  <p className="mt-2 text-sm text-neutral-500">{meter.note}</p>
                </li>
              );
            })}
          </ul>
        </section>

        <section className="flex flex-col gap-4">
          <h2 className="text-xl font-semibold tracking-tight">Durable tier by class</h2>
          <p className="text-sm text-neutral-400">
            Not one total. Buildpics are negligible and the map corpus is fixed at about
            3,575, so renders are the only class that can move, and a total that is growing
            does not say which one did.
          </p>
          {report.durable.length === 0 ? (
            <p className={`${CARD} text-sm text-neutral-400`}>Nothing promoted yet.</p>
          ) : (
            <ul className="flex flex-col gap-2">
              {report.durable.map((held) => (
                <li
                  key={held.name}
                  className="flex items-baseline justify-between gap-3 rounded-md border border-neutral-800 bg-neutral-950 px-5 py-3 text-sm"
                >
                  <span className="font-medium">{held.name}</span>
                  <span className="text-neutral-400">
                    {held.objects} object{held.objects === 1 ? "" : "s"},{" "}
                    {formatBytes(held.bytes)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="flex flex-col gap-4">
          <h2 className="text-xl font-semibold tracking-tight">Waiting to be swept</h2>
          <p className="text-sm text-neutral-400">
            Staging objects no row points at: bytes superseded by a newer archive, and
            uploads whose row was never written. The daily sweep deletes them, which is
            free. An object the hub never managed to write down at all is not here and
            cannot be, because finding it would mean listing the store.
          </p>
          {orphans.length === 0 ? (
            <p className={`${CARD} text-sm text-neutral-400`}>Nothing unclaimed.</p>
          ) : (
            <ul className="flex flex-col gap-2">
              {orphans.map((orphan) => (
                <li
                  key={orphan.id}
                  className="flex flex-wrap items-baseline justify-between gap-3 rounded-md border border-neutral-800 bg-neutral-950 px-5 py-3 text-sm"
                >
                  <span className="font-medium">{orphan.reason}</span>
                  <span className="text-neutral-400">
                    {formatBytes(orphan.bytes)}, since{" "}
                    {new Date(orphan.at).toISOString().slice(0, 10)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </main>
  );
}

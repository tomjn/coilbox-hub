import { notFound } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { ModerationNav } from "@/components/ModerationNav";
import {
  formatBytes,
  headroom,
  METER_ALERT_FRACTION,
  type Meter,
  fetchMeters,
} from "@/lib/assets/meters";
import {
  ABANDONED_RESERVATION_MINUTES,
  CLEANUP_BATCH,
  fetchAbandonedStagedDeletions,
  fetchUnclaimedStagedObjects,
  type UnclaimedStagedObject,
} from "@/lib/assets/orphan";
import {
  fetchPromotionStatus,
  PROMOTION_AGE_DAYS,
  type PromotionStatus,
} from "@/lib/assets/promote";
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
 * ## What it leads with
 *
 * Promotion, because the staging bucket only stays small while that job keeps
 * moving pictures out, and next to it what the sweep has still to delete. Until
 * September 2026 the page led with Vercel Blob's advanced operations. Uploads
 * go to the Supabase bucket now (#332), so `lib/assets/meters.ts` has no Blob
 * meters left to show.
 *
 * ## Why every number wears its basis
 *
 * Three of the meters are not measurable from here at all. Neither Vercel nor
 * Supabase publishes an API for what a project has used, so they are dashboard
 * readings and this page says so instead of showing a plausible looking zero.
 * They are kept small, because there is nothing on them to read.
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
 * pending and rejected rows that `asset_read_approved` hides and list the
 * bucket, which nothing else may do.
 */

// Fainter than the text pages. This one is mostly figures and a chart, and a
// drawing behind a chart competes with the columns.
const BACKDROP_STRENGTH = 0.05;

const CARD = "rounded-md border border-neutral-800 bg-card p-5";

/** What the three bases mean, in a phrase each, so the label is legible without
 *  reading the note underneath it. */
const BASIS_LABEL = {
  counted: "counted from the database",
  estimated: "estimated from the database",
  dashboard: "dashboard only",
} as const;

const number = new Intl.NumberFormat("en-GB");

function shortDate(day: string): string {
  return new Date(`${day.slice(0, 10)}T00:00:00.000Z`).toLocaleDateString("en-GB", {
    day: "numeric",
    month: "short",
    timeZone: "UTC",
  });
}

function stamp(at: string): string {
  return new Date(at).toISOString().replace("T", " ").slice(0, 16);
}

function used(meter: Meter): string {
  return meter.used === null ? "no figure" : formatBytes(meter.used);
}

/** A state in words and a colour for the bar. The words carry it: the colour is
 *  never the only thing saying a meter is in trouble. */
function standing(full: number) {
  if (full >= 1) return { label: "Full", bar: "bg-red-400", ink: "text-red-300" };
  if (full >= METER_ALERT_FRACTION) {
    return {
      label: `Past ${Math.round(METER_ALERT_FRACTION * 100)}%, the daily job is failing`,
      bar: "bg-amber-400",
      ink: "text-amber-300",
    };
  }
  return { label: "Room to spare", bar: "bg-neutral-300", ink: "text-neutral-400" };
}

/** A fill bar with a tick where the daily job starts failing. */
function MeterBar({ full, bar }: { full: number; bar: string }) {
  return (
    <div className="relative mt-3 h-2 rounded-full bg-neutral-800">
      <div
        className={`h-full rounded-full ${bar}`}
        style={{ width: `${Math.min(full, 1) * 100}%` }}
      />
      <div
        title={`The daily job fails from ${Math.round(METER_ALERT_FRACTION * 100)}%`}
        className="absolute -top-1 h-4 w-px bg-neutral-500"
        style={{ left: `${METER_ALERT_FRACTION * 100}%` }}
      />
    </div>
  );
}

function PromotionPanel({
  status,
  stuckDeletions,
}: {
  status: PromotionStatus;
  /** Bucket objects reserved for deletion more than {@link
   *  ABANDONED_RESERVATION_MINUTES} minutes ago: a sweep that died mid delete,
   *  rather than one still running (issue #337). The next sweep finishes them. */
  stuckDeletions: number;
}) {
  const figure = (value: number | null) => (value === null ? "no figure" : number.format(value));

  const rows: { label: string; value: string; detail?: string; alarm?: boolean }[] = [
    { label: "Waiting for review", value: figure(status.pending) },
    {
      label: "Waiting for Coilbox to upload again",
      value: figure(status.missing),
      detail:
        status.missing
          ? "Their bytes were lost with the old staging store. Pages and promotion skip them, and Coilbox uploads each one into the bucket the next time it offers that picture."
          : undefined,
    },
    {
      label: `Approved, in the ${PROMOTION_AGE_DAYS} day hold`,
      value: figure(status.waiting),
    },
    {
      label: "Due to move now",
      value: figure(status.due),
      detail: status.dueSince
        ? status.stalled
          ? `Due since ${stamp(status.dueSince)} UTC, which is longer than one daily run. Check the Promote workflow in tomjn/coilbox-assets.`
          : `Due since ${stamp(status.dueSince)} UTC. The next daily run moves it.`
        : undefined,
      alarm: status.stalled,
    },
    {
      label: "Staging copies still to delete",
      value: figure(status.leftover),
    },
    {
      label: "Bucket deletions stuck",
      value: figure(stuckDeletions),
      detail:
        stuckDeletions > 0
          ? `Reserved for deletion more than ${ABANDONED_RESERVATION_MINUTES} minutes ago, so the run that reserved them has died. The next sweep finishes the delete.`
          : undefined,
      alarm: stuckDeletions > 0,
    },
    { label: "Promoted in the last 30 days", value: figure(status.promotedRecently) },
    {
      label: "Last promoted",
      value: status.lastPromotedAt ? `${stamp(status.lastPromotedAt)} UTC` : "never",
    },
  ];

  return (
    <section className={CARD} aria-labelledby="promotion">
      <h2 id="promotion" className="text-lg font-semibold tracking-tight">
        Promotion
      </h2>
      <p className="mt-2 max-w-[70ch] text-sm text-neutral-400">
        Approved pictures move out of the staging bucket and into the durable tier. The
        bucket only stays small while this keeps moving.
      </p>
      <dl className="mt-4 flex flex-col">
        {rows.map((row) => (
          <div key={row.label} className="border-t border-neutral-900 py-2.5">
            <div className="flex items-baseline justify-between gap-3 text-sm">
              <dt className="text-neutral-400">{row.label}</dt>
              <dd className={`tabular-nums ${row.alarm ? "text-amber-300" : "text-neutral-100"}`}>
                {row.value}
              </dd>
            </div>
            {row.detail ? (
              <p className={`mt-1 text-xs ${row.alarm ? "text-amber-300" : "text-neutral-500"}`}>
                {row.detail}
              </p>
            ) : null}
          </div>
        ))}
      </dl>
    </section>
  );
}

function StorageMeter({ meter }: { meter: Meter }) {
  const full = headroom(meter);
  const state = full === null ? null : standing(full);

  return (
    <li className={CARD}>
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <h3 className="text-base font-medium">{meter.name}</h3>
        <span className="text-xs text-neutral-500">{BASIS_LABEL[meter.basis]}</span>
      </div>
      <p className="mt-2 text-sm text-neutral-300">
        <span className="text-xl font-semibold tabular-nums text-neutral-100">{used(meter)}</span>{" "}
        of {formatBytes(meter.allowance)}
        {full === null ? null : ` (${Math.round(full * 100)}%)`}
      </p>
      {full !== null && state ? <MeterBar full={full} bar={state.bar} /> : null}
      <p className="mt-3 max-w-[70ch] text-sm text-neutral-500">{meter.note}</p>
    </li>
  );
}

function Table({
  head,
  rows,
  foot,
}: {
  head: string[];
  rows: { key: string; cells: React.ReactNode[] }[];
  foot?: React.ReactNode[];
}) {
  return (
    <table className="w-full text-sm tabular-nums">
      <thead className="text-left text-neutral-500">
        <tr>
          {head.map((label, index) => (
            <th key={label} className={`pb-2 font-normal ${index > 0 ? "text-right" : ""}`}>
              {label}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((row) => (
          <tr key={row.key} className="border-t border-neutral-900">
            {row.cells.map((cell, index) => (
              <td key={index} className={`py-2 ${index > 0 ? "text-right text-neutral-300" : ""}`}>
                {cell}
              </td>
            ))}
          </tr>
        ))}
      </tbody>
      {foot ? (
        <tfoot>
          <tr className="border-t border-neutral-700 text-neutral-100">
            {foot.map((cell, index) => (
              <td key={index} className={`pt-2 ${index > 0 ? "text-right" : ""}`}>
                {cell}
              </td>
            ))}
          </tr>
        </tfoot>
      ) : null}
    </table>
  );
}

/** The unclaimed bucket objects as one line: a list of 200 hashes says less. */
function unclaimedSummary(objects: UnclaimedStagedObject[]) {
  return {
    objects: objects.length,
    bytes: objects.reduce((sum, object) => sum + object.bytes, 0),
    since: objects.reduce<string | null>(
      (oldest, object) => (oldest === null || object.created_at < oldest ? object.created_at : oldest),
      null,
    ),
  };
}

export default async function Ops() {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  if (!allowed) notFound();

  const admin = createAdminClient();
  const now = new Date();
  const [report, unclaimed, promotion, stuckDeletions] = await Promise.all([
    fetchMeters(admin, now),
    fetchUnclaimedStagedObjects(admin, CLEANUP_BATCH),
    fetchPromotionStatus(admin, now),
    fetchAbandonedStagedDeletions(admin, now),
  ]);

  const measured = report.meters.filter((meter) => meter.basis !== "dashboard");
  const dashboard = report.meters.filter((meter) => meter.basis === "dashboard");
  const durableTotal = report.durable.reduce((sum, held) => sum + held.bytes, 0);
  const swept = unclaimedSummary(unclaimed);

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <ModerationNav current="allowances" />
      <div className="relative z-10 flex w-full flex-col gap-10 px-6 py-10">
        <header className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="text-3xl font-semibold tracking-tight">Allowances</h1>
            <p className="mt-2 max-w-[70ch] text-sm text-neutral-400">
              What the hub holds and serves, against the free plan allowances. The daily sweep
              fails on purpose once a counted meter passes{" "}
              {Math.round(METER_ALERT_FRACTION * 100)}%.
            </p>
          </div>
          <p className="text-xs text-neutral-500">Read {stamp(report.at)} UTC</p>
        </header>

        <div className="grid items-start gap-6 lg:grid-cols-2">
          <PromotionPanel status={promotion} stuckDeletions={stuckDeletions.length} />
          <section className={CARD} aria-labelledby="swept">
            <h2 id="swept" className="text-lg font-semibold tracking-tight">
              Waiting to be swept
            </h2>
            <p className="mt-2 mb-4 max-w-[70ch] text-sm text-neutral-400">
              Bucket objects no row points at: bytes superseded by a newer archive, and uploads
              whose row was never written. The daily sweep deletes them.
            </p>
            {swept.objects === 0 ? (
              <p className="text-sm text-neutral-400">Nothing unclaimed.</p>
            ) : (
              <Table
                head={["Objects", "Size", "Oldest"]}
                rows={[
                  {
                    key: "unclaimed",
                    cells: [
                      // The listing stops at one sweep's worth, so a full one is a floor.
                      `${swept.objects === CLEANUP_BATCH ? "at least " : ""}${number.format(swept.objects)}`,
                      formatBytes(swept.bytes),
                      swept.since ? shortDate(swept.since) : "",
                    ],
                  },
                ]}
              />
            )}
          </section>
        </div>

        <section className="flex flex-col gap-4" aria-labelledby="storage">
          <h2 id="storage" className="text-xl font-semibold tracking-tight">
            Storage
          </h2>
          <ul className="grid gap-6 lg:grid-cols-2">
            {measured.map((meter) => (
              <StorageMeter key={meter.name} meter={meter} />
            ))}
          </ul>
        </section>

        <section className="flex flex-col gap-4" aria-labelledby="dashboard">
          <div>
            <h2 id="dashboard" className="text-xl font-semibold tracking-tight">
              Only on the dashboards
            </h2>
            <p className="mt-1 max-w-[70ch] text-sm text-neutral-400">
              Nothing here can measure these, so there is no figure rather than a zero.
            </p>
          </div>
          <ul className="grid gap-x-6 gap-y-4 md:grid-cols-2 xl:grid-cols-3">
            {dashboard.map((meter) => (
              <li key={meter.name} className="border-t border-neutral-800 pt-3">
                <p className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                  <span className="font-medium">{meter.name}</span>
                  <span className="text-neutral-500">{formatBytes(meter.allowance)}</span>
                </p>
                <p className="mt-1 text-xs text-neutral-500">{meter.note}</p>
              </li>
            ))}
          </ul>
        </section>

        <div className="grid items-start gap-6 lg:grid-cols-2">
          <section className={CARD} aria-labelledby="durable">
            <h2 id="durable" className="text-lg font-semibold tracking-tight">
              Durable tier by class
            </h2>
            <p className="mt-2 mb-4 max-w-[70ch] text-sm text-neutral-400">
              Not one total. Buildpics are negligible and the map corpus is fixed at about
              3,575, so renders are the only class that can move, and a total that is growing
              does not say which one did.
            </p>
            {report.durable.length === 0 ? (
              <p className="text-sm text-neutral-400">Nothing promoted yet.</p>
            ) : (
              <Table
                head={["Class", "Objects", "Size", "Share"]}
                rows={report.durable.map((held) => ({
                  key: held.name,
                  cells: [
                    held.name,
                    number.format(held.objects),
                    formatBytes(held.bytes),
                    `${durableTotal > 0 ? Math.round((held.bytes / durableTotal) * 100) : 0}%`,
                  ],
                }))}
                foot={[
                  "Total",
                  number.format(report.durable.reduce((sum, held) => sum + held.objects, 0)),
                  formatBytes(durableTotal),
                  "",
                ]}
              />
            )}
          </section>

        </div>
      </div>
    </main>
  );
}

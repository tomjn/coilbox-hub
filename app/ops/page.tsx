import { notFound } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { ModerationNav } from "@/components/ModerationNav";
import {
  BLOB_ADVANCED_OPERATIONS_ALLOWANCE,
  BLOB_PUT_BUDGET,
  type BlobPutDay,
  fetchBlobPutDays,
  uploadsResume,
} from "@/lib/assets/blobLedger";
import {
  formatBytes,
  headroom,
  METER_ALERT_FRACTION,
  type Meter,
  fetchMeters,
} from "@/lib/assets/meters";
import { fetchOrphans, type Orphan } from "@/lib/assets/orphan";
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
 * Advanced operations, with the days they were spent on. In September 2026 the
 * store was suspended by one release's worth of uploads in late August, and a
 * single total would not have said that, or when the room would come back. Next
 * to it is promotion, because the staging tier only stays small while that job
 * keeps moving pictures out.
 *
 * ## Why every number wears its basis
 *
 * Three of the meters are not measurable from here at all. Vercel publishes no
 * API for what a Hobby project has used, so they are dashboard readings and this
 * page says so instead of showing a plausible looking zero. They are kept small,
 * because there is nothing on them to read.
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

// Fainter than the text pages. This one is mostly figures and a chart, and a
// drawing behind a chart competes with the columns.
const BACKDROP_STRENGTH = 0.05;

const CARD = "rounded-md border border-neutral-800 bg-neutral-950 p-5";

/**
 * The two kinds of put, in the order they stack. Checked with the dataviz
 * palette validator against `bg-neutral-950`: both clear 3:1 on it, and the pair
 * is well apart for every kind of colour vision. Never the text colour of
 * anything: the legend and the tooltip name them in ink.
 */
const SERIES = [
  { key: "asset", label: "Pictures", swatch: "bg-[#3987e5]" },
  { key: "gameImage", label: "Game logos and banners", swatch: "bg-[#d95926]" },
] as const;

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
  if (meter.used === null) return "no figure";
  return meter.unit === "bytes" ? formatBytes(meter.used) : number.format(meter.used);
}

function allowance(meter: Meter): string {
  return meter.unit === "bytes"
    ? formatBytes(meter.allowance)
    : `${number.format(meter.allowance)} ${meter.unit}`;
}

/** A state in words and a colour for the bar. The words carry it: the colour is
 *  never the only thing saying a meter is in trouble. */
function standing(full: number, refusedAt: number) {
  if (full >= refusedAt) return { label: "Refusing uploads", bar: "bg-red-400", ink: "text-red-300" };
  if (full >= METER_ALERT_FRACTION) {
    return {
      label: `Past ${Math.round(METER_ALERT_FRACTION * 100)}%, the daily job is failing`,
      bar: "bg-amber-400",
      ink: "text-amber-300",
    };
  }
  return { label: "Room to spare", bar: "bg-neutral-300", ink: "text-neutral-400" };
}

/** A fill bar with ticks where something happens. */
function MeterBar({
  full,
  bar,
  ticks = [],
}: {
  full: number;
  bar: string;
  ticks?: { at: number; label: string }[];
}) {
  return (
    <div className="relative mt-3 h-2 rounded-full bg-neutral-800">
      <div
        className={`h-full rounded-full ${bar}`}
        style={{ width: `${Math.min(full, 1) * 100}%` }}
      />
      {ticks.map((tick) => (
        <div
          key={tick.label}
          title={tick.label}
          className="absolute -top-1 h-4 w-px bg-neutral-500"
          style={{ left: `${tick.at * 100}%` }}
        />
      ))}
    </div>
  );
}

function PutChart({ days }: { days: BlobPutDay[] }) {
  const peak = Math.max(1, ...days.map((day) => day.asset + day.gameImage));

  return (
    <figure className="mt-6">
      <figcaption className="flex flex-wrap items-baseline justify-between gap-3 text-sm">
        <span className="text-neutral-300">Puts by day, UTC</span>
        <span className="flex flex-wrap gap-4 text-xs text-neutral-400">
          {SERIES.map((series) => (
            <span key={series.key} className="flex items-center gap-1.5">
              <span className={`h-2.5 w-2.5 rounded-sm ${series.swatch}`} aria-hidden />
              {series.label}
            </span>
          ))}
        </span>
      </figcaption>

      <div className="relative mt-3">
        <span className="absolute left-0 top-0 text-xs tabular-nums text-neutral-500">
          {number.format(peak)}
        </span>
        <ol
          className="flex h-44 items-end gap-[2px] border-b border-neutral-800 pt-5"
          aria-label="Puts by day. The table below has the same figures."
        >
          {days.map((day) => {
            const total = day.asset + day.gameImage;
            return (
              <li
                key={day.day}
                tabIndex={0}
                className="group relative flex h-full flex-1 flex-col justify-end rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-neutral-400"
              >
                <span className="sr-only">
                  {shortDate(day.day)}: {total} puts
                </span>
                {total > 0 ? (
                  <span className="flex flex-col gap-[2px]" style={{ height: `${(total / peak) * 100}%` }}>
                    {day.gameImage > 0 ? (
                      <span
                        className={`${SERIES[1].swatch} rounded-t-[4px]`}
                        style={{ flexGrow: day.gameImage }}
                      />
                    ) : null}
                    {day.asset > 0 ? (
                      <span
                        className={`${SERIES[0].swatch} ${day.gameImage > 0 ? "" : "rounded-t-[4px]"}`}
                        style={{ flexGrow: day.asset }}
                      />
                    ) : null}
                  </span>
                ) : null}
                <span
                  aria-hidden
                  className="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 hidden -translate-x-1/2 whitespace-nowrap rounded border border-neutral-700 bg-neutral-900 px-2.5 py-1.5 text-xs text-neutral-200 shadow-[0_4px_12px_rgb(0_0_0/0.5)] group-hover:block group-focus-visible:block"
                >
                  <span className="block font-medium">{shortDate(day.day)}</span>
                  <span className="block tabular-nums text-neutral-400">
                    {number.format(day.asset)} pictures, {number.format(day.gameImage)} game
                  </span>
                </span>
              </li>
            );
          })}
        </ol>
        <ol className="mt-1.5 flex gap-[2px] text-xs text-neutral-500" aria-hidden>
          {days.map((day, index) => (
            <li key={day.day} className="relative flex-1">
              {/* Weekly, and the last day, but not a weekly one so close to the
                  last that the two labels would overlap on a phone. */}
              {index === days.length - 1 || (index % 7 === 0 && days.length - 1 - index >= 5) ? (
                <span
                  className={`absolute whitespace-nowrap ${index === days.length - 1 ? "right-0" : "left-0"}`}
                >
                  {shortDate(day.day)}
                </span>
              ) : null}
            </li>
          ))}
        </ol>
      </div>

      <details className="mt-8 text-sm">
        <summary className="cursor-pointer text-neutral-400 hover:text-neutral-200">
          The same figures as a table
        </summary>
        <table className="mt-3 w-full max-w-md tabular-nums">
          <thead className="text-left text-neutral-500">
            <tr>
              <th className="py-1 font-normal">Day</th>
              <th className="py-1 text-right font-normal">Pictures</th>
              <th className="py-1 text-right font-normal">Game</th>
            </tr>
          </thead>
          <tbody>
            {days
              .filter((day) => day.asset + day.gameImage > 0)
              .map((day) => (
                <tr key={day.day} className="border-t border-neutral-900">
                  <td className="py-1">{day.day}</td>
                  <td className="py-1 text-right">{number.format(day.asset)}</td>
                  <td className="py-1 text-right">{number.format(day.gameImage)}</td>
                </tr>
              ))}
          </tbody>
        </table>
      </details>
    </figure>
  );
}

function OperationsPanel({ meter, days }: { meter: Meter; days: BlobPutDay[] | null }) {
  const full = headroom(meter);
  const refusedAt = BLOB_PUT_BUDGET / BLOB_ADVANCED_OPERATIONS_ALLOWANCE;
  const state = full === null ? null : standing(full, refusedAt);
  const resume = days ? uploadsResume(days) : null;
  const busiest = days
    ? days.reduce<BlobPutDay | null>(
        (best, day) =>
          day.asset + day.gameImage > (best ? best.asset + best.gameImage : 0) ? day : best,
        null,
      )
    : null;

  return (
    <section className={`${CARD} xl:col-span-2`} aria-labelledby="operations">
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <h2 id="operations" className="text-lg font-semibold tracking-tight">
          {meter.name}
        </h2>
        <span className="text-xs text-neutral-500">{BASIS_LABEL[meter.basis]}</span>
      </div>

      <p className="mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span className="text-4xl font-semibold tabular-nums tracking-tight">{used(meter)}</span>
        <span className="text-neutral-400">of {allowance(meter)}</span>
        {state ? <span className={`text-sm ${state.ink}`}>{state.label}</span> : null}
      </p>

      {full === null || !state ? (
        <p className="mt-3 text-sm text-red-300">The count could not be read.</p>
      ) : (
        <>
          <MeterBar
            full={full}
            bar={state.bar}
            ticks={[
              {
                at: METER_ALERT_FRACTION,
                label: `The daily job fails from ${number.format(METER_ALERT_FRACTION * BLOB_ADVANCED_OPERATIONS_ALLOWANCE)}`,
              },
              { at: refusedAt, label: `Uploads are refused from ${number.format(BLOB_PUT_BUDGET)}` },
            ]}
          />
          <p className="mt-2 flex flex-wrap justify-between gap-2 text-xs text-neutral-500">
            <span>
              The daily job fails from{" "}
              {number.format(METER_ALERT_FRACTION * BLOB_ADVANCED_OPERATIONS_ALLOWANCE)}
            </span>
            <span>Uploads are refused from {number.format(BLOB_PUT_BUDGET)}</span>
          </p>
        </>
      )}

      <p className="mt-4 max-w-[70ch] text-sm text-neutral-300">
        {resume
          ? `Uploads are refused until ${shortDate(resume)}, when enough of the oldest puts stop counting.`
          : busiest
            ? `The busiest day was ${shortDate(busiest.day)}, with ${number.format(busiest.asset + busiest.gameImage)} puts. Each day stops counting 30 days after it.`
            : "Nothing has been put in the last 30 days."}
      </p>

      {days ? (
        <PutChart days={days} />
      ) : (
        <p className="mt-6 text-sm text-red-300">The puts by day could not be read.</p>
      )}

      <p className="mt-4 max-w-[70ch] text-sm text-neutral-500">{meter.note}</p>
    </section>
  );
}

function PromotionPanel({ status }: { status: PromotionStatus }) {
  const figure = (value: number | null) => (value === null ? "no figure" : number.format(value));

  const rows: { label: string; value: string; detail?: string; alarm?: boolean }[] = [
    { label: "Waiting for review", value: figure(status.pending) },
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
        Approved pictures move out of the Blob store and into the durable tier. The store
        only stays small while this keeps moving.
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
  const state = full === null ? null : standing(full, Number.POSITIVE_INFINITY);

  return (
    <li className={CARD}>
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <h3 className="text-base font-medium">{meter.name}</h3>
        <span className="text-xs text-neutral-500">{BASIS_LABEL[meter.basis]}</span>
      </div>
      <p className="mt-2 text-sm text-neutral-300">
        <span className="text-xl font-semibold tabular-nums text-neutral-100">{used(meter)}</span>{" "}
        of {allowance(meter)}
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

/** Orphans by reason. A list of 200 suffixed paths says less than two lines. */
function orphanGroups(orphans: Orphan[]) {
  const groups = new Map<string, { reason: string; objects: number; bytes: number; since: string }>();
  for (const orphan of orphans) {
    const held = groups.get(orphan.reason) ?? {
      reason: orphan.reason,
      objects: 0,
      bytes: 0,
      since: orphan.at,
    };
    held.objects++;
    held.bytes += orphan.bytes;
    if (orphan.at < held.since) held.since = orphan.at;
    groups.set(orphan.reason, held);
  }
  return [...groups.values()];
}

export default async function Ops() {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  if (!allowed) notFound();

  const admin = createAdminClient();
  const now = new Date();
  const [report, orphans, days, promotion] = await Promise.all([
    fetchMeters(admin, now),
    fetchOrphans(admin),
    fetchBlobPutDays(admin, now),
    fetchPromotionStatus(admin, now),
  ]);

  const operations = report.meters.find((meter) => meter.unit === "operations");
  const measured = report.meters.filter(
    (meter) => meter.unit !== "operations" && meter.basis !== "dashboard",
  );
  const dashboard = report.meters.filter((meter) => meter.basis === "dashboard");
  const durableTotal = report.durable.reduce((sum, held) => sum + held.bytes, 0);
  const groups = orphanGroups(orphans);

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <ModerationNav current="allowances" />
      <div className="relative z-10 flex w-full flex-col gap-10 px-6 py-10">
        <header className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="text-3xl font-semibold tracking-tight">Allowances</h1>
            <p className="mt-2 max-w-[70ch] text-sm text-neutral-400">
              Going over {number.format(BLOB_ADVANCED_OPERATIONS_ALLOWANCE)} Blob advanced
              operations in any 30 days suspends the store for 30 days and cannot be paid
              through, so that is the one to watch. The daily sweep fails on purpose once a
              counted meter passes {Math.round(METER_ALERT_FRACTION * 100)}%.
            </p>
          </div>
          <p className="text-xs text-neutral-500">Read {stamp(report.at)} UTC</p>
        </header>

        <div className="grid items-start gap-6 xl:grid-cols-3">
          {operations ? <OperationsPanel meter={operations} days={days} /> : null}
          <PromotionPanel status={promotion} />
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
                  <span className="text-neutral-500">{allowance(meter)}</span>
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

          <section className={CARD} aria-labelledby="swept">
            <h2 id="swept" className="text-lg font-semibold tracking-tight">
              Waiting to be swept
            </h2>
            <p className="mt-2 mb-4 max-w-[70ch] text-sm text-neutral-400">
              Staging objects no row points at: bytes superseded by a newer archive, and
              uploads whose row was never written. The daily sweep deletes them, which is free.
              An object the hub never managed to write down at all is not here and cannot be,
              because finding it would mean listing the store.
            </p>
            {groups.length === 0 ? (
              <p className="text-sm text-neutral-400">Nothing unclaimed.</p>
            ) : (
              <Table
                head={["Reason", "Objects", "Size", "Oldest"]}
                rows={groups.map((group) => ({
                  key: group.reason,
                  cells: [
                    group.reason,
                    number.format(group.objects),
                    formatBytes(group.bytes),
                    shortDate(group.since),
                  ],
                }))}
              />
            )}
          </section>
        </div>
      </div>
    </main>
  );
}

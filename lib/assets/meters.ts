import type { SupabaseClient } from "@supabase/supabase-js";
import { UNIT_RENDER_VARIANT_PREFIX } from "./asset";
import { BLOB_ADVANCED_OPERATIONS_PER_MONTH } from "./blob";

/**
 * What the hub is spending, against the allowances that can end it (issue #113).
 *
 * ## Why every number says how it was arrived at
 *
 * Vercel does not publish the Hobby allowances in any API, and the four numbers
 * below were read off a dashboard by hand on 2026-08-14 (#99). There is no
 * endpoint to ask what has been used either. So a meter here is one of three
 * things and always says which:
 *
 * - `counted`, worked out from Postgres, which knows because every object in the
 *   staging store has a row and the ones that stopped having one are in
 *   `public.asset_orphan`
 * - `estimated`, worked out from Postgres for something Postgres only partly
 *   describes
 * - `dashboard`, not measurable from here at all, reported as absent
 *
 * A meter that quietly reported a stale constant as if it were live would be
 * worse than no meter, because the whole point of the exercise is that the one
 * allowance that cannot be paid through stops being a surprise.
 *
 * ## The one that binds
 *
 * Advanced operations. Going over 2,000 in a month removes Blob access for 30
 * days, there is no overage billing, and a month of the hub refusing every
 * upload is an outage rather than a bill. The upload route already refuses at
 * {@link MONTHLY_UPLOAD_BUDGET}, which is 100 below the allowance, so the hub
 * cannot reach the lockout through its own front door. What this adds is warning
 * before that refusal starts, because a hub that has stopped taking pictures is
 * also a failure and one nobody gets an email about.
 *
 * {@link headroomAlerts} is that warning, and the channel is a scheduled job
 * exiting non-zero. That is not elegant and it is the only alerting this project
 * has: a failed GitHub Actions run emails the repository owner, and nothing else
 * here can reach anybody.
 */

/** One gibibyte, which is how Vercel and GitHub both express these. */
const GIB = 1024 * 1024 * 1024;

/** Read off the Blob store dashboard on 2026-08-14 (#99). */
export const BLOB_STORAGE_ALLOWANCE_BYTES = GIB;
export const BLOB_DATA_TRANSFER_ALLOWANCE_BYTES = 10 * GIB;

/** Shared between the site and anything else served through Vercel, which is
 *  why a staging read of the corpus shows up on the same meter as real visits. */
export const VERCEL_FAST_DATA_TRANSFER_ALLOWANCE_BYTES = 100 * GIB;

/** GitHub Pages publishes a site of at most 1 GB, and asks for under 100 GB of
 *  bandwidth a month. The second is a soft limit and the first is not. */
export const PAGES_PUBLISHED_ALLOWANCE_BYTES = GIB;
export const PAGES_BANDWIDTH_SOFT_ALLOWANCE_BYTES = 100 * GIB;

/**
 * How full a counted meter has to be before the daily job starts failing.
 *
 * Three quarters, so the operations meter alerts at 1,500 of 2,000. That leaves
 * 400 uploads between the first red run and the route refusing anything, which
 * at the volume this hub takes is weeks rather than hours.
 *
 * One fraction for every meter rather than a number each. They are all "this
 * ends badly at 100%" and inventing a separate threshold per meter would be
 * three more numbers to defend and keep in step.
 */
export const METER_ALERT_FRACTION = 0.75;

export type MeterBasis = "counted" | "estimated" | "dashboard";

export interface Meter {
  name: string;
  basis: MeterBasis;
  /** Null on a `dashboard` meter, which is the whole of what makes it one. */
  used: number | null;
  allowance: number;
  unit: "operations" | "bytes";
  /** What the number is, and what it leaves out. Rendered next to it: a basis
   *  that is not on the screen is a basis nobody reads. */
  note: string;
}

/**
 * One class of picture in the durable tier.
 *
 * The breakdown #113 asks for instead of a total. Buildpics are negligible and
 * the map corpus is fixed at about 3,575, so renders are the only class that can
 * move, and a total that is growing says nothing about which one did.
 */
export interface DurableClass {
  /** `buildpic`, `render`, or one of the four map variants. */
  name: string;
  objects: number;
  bytes: number;
}

export interface MeterReport {
  meters: Meter[];
  durable: DurableClass[];
  /** When it was read, so a page that is left open says so. */
  at: string;
}

/** What `public.asset_storage_usage()` answers with. */
interface UsageRow {
  tier: string;
  variant: string;
  objects: number;
  bytes: number;
}

/**
 * The class a variant belongs to.
 *
 * Render angles collapse into one class, because the angle is part of the
 * identity and not of the accounting: eight angles of one unit is one thing
 * growing, and eight lines saying so is a breakdown nobody reads.
 */
export function assetClass(variant: string): string {
  return variant.startsWith(UNIT_RENDER_VARIANT_PREFIX) ? "render" : variant;
}

/** Beginning of the current calendar month, in UTC, matching the window
 *  `checkAssetUpload` measures the monthly budget over. */
function monthStart(now: Date): string {
  return new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1)).toISOString();
}

/** A count, or null when the query failed. Null is not zero: a meter that read a
 *  broken query as an empty store would report full headroom at exactly the
 *  moment nobody can check. */
async function countRows(
  query: PromiseLike<{ count: number | null; error: unknown }>,
): Promise<number | null> {
  const { count, error } = await query;
  return error ? null : (count ?? 0);
}

function total(rows: UsageRow[], tier: string): number {
  return rows.filter((row) => row.tier === tier).reduce((sum, row) => sum + row.bytes, 0);
}

/** The durable tier by class, largest first, which is the order somebody
 *  looking for what moved wants to read it in. */
export function durableClasses(rows: UsageRow[]): DurableClass[] {
  const classes = new Map<string, DurableClass>();

  for (const row of rows.filter((candidate) => candidate.tier === "static")) {
    const name = assetClass(row.variant);
    const held = classes.get(name) ?? { name, objects: 0, bytes: 0 };
    held.objects += row.objects;
    held.bytes += row.bytes;
    classes.set(name, held);
  }

  return [...classes.values()].sort((a, b) => b.bytes - a.bytes);
}

/**
 * Every meter, read now.
 *
 * Wants the secret key. `public.asset_orphan` is readable by nothing else, and
 * the counts have to include pending and rejected rows, which
 * `asset_read_approved` hides.
 */
export async function fetchMeters(
  supabase: SupabaseClient,
  now: Date = new Date(),
): Promise<MeterReport> {
  const since = monthStart(now);

  const [usage, uploads, unclaimed] = await Promise.all([
    supabase.rpc("asset_storage_usage"),
    // One `put()` each. `uploaded_by` is null on a seeded row, which is written
    // straight to the durable tier and spends nothing, and `seen_at` is the
    // column a replacement moves, which is why the upload route counts the same
    // two things the same way.
    countRows(
      supabase
        .from("asset")
        .select("id", { count: "exact", head: true })
        .not("uploaded_by", "is", null)
        .gte("seen_at", since),
    ),
    // A `put()` that left no row to count. See `./orphan`.
    countRows(
      supabase
        .from("asset_orphan")
        .select("id", { count: "exact", head: true })
        .eq("reason", "unclaimed")
        .gte("at", since),
    ),
  ]);

  if (usage.error) throw new Error(`Could not read what the stores hold: ${usage.error.message}`);

  const rows = (usage.data ?? []) as unknown as UsageRow[];
  const durable = durableClasses(rows);

  const operations =
    uploads === null || unclaimed === null ? null : uploads + unclaimed;

  return {
    at: now.toISOString(),
    durable,
    meters: [
      {
        name: "Blob advanced operations this month",
        basis: "counted",
        used: operations,
        allowance: BLOB_ADVANCED_OPERATIONS_PER_MONTH,
        unit: "operations",
        note:
          "Every accepted upload is one put() and the hub makes no other advanced operation. " +
          "Counts the hub's own spend only: browsing the store in the Vercel dashboard lists " +
          "blobs, which is an advanced operation nothing here can see. A put() whose row write " +
          "failed and whose object was then deleted also leaves nothing to count, which is what " +
          "the 100 operation margin below the allowance is for.",
      },
      {
        name: "Blob storage",
        basis: "counted",
        used: total(rows, "blob") + total(rows, "orphan"),
        allowance: BLOB_STORAGE_ALLOWANCE_BYTES,
        unit: "bytes",
        note:
          "The encoded length of every staging row plus every object still queued for sweeping. " +
          "Excludes per object overhead the store charges and this cannot see.",
      },
      {
        name: "Blob data transfer this month",
        basis: "dashboard",
        used: null,
        allowance: BLOB_DATA_TRANSFER_ALLOWANCE_BYTES,
        unit: "bytes",
        note:
          "The store is public, so a browser fetches a staging picture straight from it and no " +
          "part of that request reaches the hub. Nothing here can count it and there is no API " +
          "to ask. Read it off the store dashboard.",
      },
      {
        name: "Vercel fast data transfer this month",
        basis: "dashboard",
        used: null,
        allowance: VERCEL_FAST_DATA_TRANSFER_ALLOWANCE_BYTES,
        unit: "bytes",
        note:
          "Everything the site serves, shared with any staging read of the corpus. Measured by " +
          "the platform and not exposed to the application. Read it off the project dashboard.",
      },
      {
        name: "Durable tier published size",
        basis: "estimated",
        used: total(rows, "static"),
        allowance: PAGES_PUBLISHED_ALLOWANCE_BYTES,
        unit: "bytes",
        note:
          "Summed from the rows that say they are on the durable tier. An estimate of the " +
          "published site rather than a measurement of it: the site also holds the atlas, the " +
          "notice and anything committed by hand, and git objects are not row bytes. See the " +
          "breakdown by class.",
      },
      {
        name: "GitHub Pages bandwidth this month",
        basis: "dashboard",
        used: null,
        allowance: PAGES_BANDWIDTH_SOFT_ALLOWANCE_BYTES,
        unit: "bytes",
        note:
          "A soft limit, and GitHub publishes no figure for it anywhere a job could read. There " +
          "is nothing to report until somebody is emailed about it.",
      },
    ],
  };
}

/** How full a meter is, or null when nothing measured it. */
export function headroom(meter: Meter): number | null {
  return meter.used === null ? null : meter.used / meter.allowance;
}

/**
 * The meters that have run out of room, as lines to print.
 *
 * Only the ones with a number behind them. A `dashboard` meter cannot alert,
 * and pretending otherwise by alerting on the constant would be the exact
 * dishonesty this file is arranged to avoid.
 *
 * Empty on an ordinary day, so the job that prints these stays quiet and a job
 * with something to say does not.
 */
export function headroomAlerts(report: MeterReport): string[] {
  return report.meters
    .filter((meter) => {
      const full = headroom(meter);
      return full !== null && full >= METER_ALERT_FRACTION;
    })
    .map(
      (meter) =>
        `${meter.name}: ${meter.used} of ${meter.allowance} ${meter.unit}, which is past ` +
        `${Math.round(METER_ALERT_FRACTION * 100)}% of the allowance.`,
    );
}

/** A byte count somebody can read at a glance. Binary units, because that is
 *  what both allowances are expressed in. */
export function formatBytes(bytes: number): string {
  const units = ["B", "KiB", "MiB", "GiB"];
  let value = bytes;
  let at = 0;
  while (value >= 1024 && at < units.length - 1) {
    value /= 1024;
    at++;
  }
  return `${at === 0 ? value : value.toFixed(1)} ${units[at]}`;
}

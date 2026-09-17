import type { SupabaseClient } from "@supabase/supabase-js";
import { UNIT_RENDER_VARIANT_PREFIX } from "./asset";

/**
 * What the hub is spending, against the allowances that can end it (issue #113).
 *
 * ## Why every number says how it was arrived at
 *
 * Neither Vercel nor Supabase tells an application what it has used, so a meter
 * here is one of three things and always says which:
 *
 * - `counted`, worked out from Postgres
 * - `estimated`, worked out from Postgres for something Postgres only partly
 *   describes
 * - `dashboard`, not measurable from here at all, reported as absent
 *
 * A meter that quietly reported a stale constant as if it were live would be
 * worse than no meter.
 *
 * ## No Blob meters
 *
 * Vercel Blob was the staging store until September 2026, and its 2,000
 * advanced operations a month were the allowance that could end the hub. Uploads
 * go to the Supabase bucket now (#332), nothing is staged in Blob that the hub
 * still wants, and #338 removes the rest. A Blob meter would only raise alarms
 * about a store the hub no longer writes to.
 *
 * {@link headroomAlerts} is the warning, and the channel is a scheduled job
 * exiting non-zero. That is not elegant and it is the only alerting this project
 * has: a failed GitHub Actions run emails the repository owner, and nothing else
 * here can reach anybody.
 */

/** One gibibyte, which is how Vercel, Supabase and GitHub all express these. */
const GIB = 1024 * 1024 * 1024;

/** The window Vercel measures Hobby usage over, rolling rather than monthly. */
const VERCEL_WINDOW_DAYS = 30;

/** Shared between the site and anything else served through Vercel, which
 *  includes every staged picture `/assets/staged/` serves. */
export const VERCEL_FAST_DATA_TRANSFER_ALLOWANCE_BYTES = 100 * GIB;

/** GitHub Pages publishes a site of at most 1 GB, and asks for under 100 GB of
 *  bandwidth a month. The second is a soft limit and the first is not. */
export const PAGES_PUBLISHED_ALLOWANCE_BYTES = GIB;
export const PAGES_BANDWIDTH_SOFT_ALLOWANCE_BYTES = 100 * GIB;

/** Supabase's free plan file storage limit (issue #337). Source:
 *  https://supabase.com/pricing. */
export const SUPABASE_STORAGE_ALLOWANCE_BYTES = GIB;

/** Supabase's free plan egress limit: 5 GB cached and 5 GB uncached, shared by
 *  the database, auth and storage across the whole organisation and not just
 *  this project (issue #337). Source: https://supabase.com/pricing and
 *  https://supabase.com/docs/guides/storage/serving/bandwidth. */
export const SUPABASE_EGRESS_ALLOWANCE_BYTES = 10 * GIB;

/**
 * How full a counted meter has to be before the daily job starts failing.
 *
 * Three quarters. The bucket is 1 GB, and its busiest day so far (2026-09-16,
 * one account's backfill) added 3.9 MiB, so the last quarter would take
 * 64 days like that to fill.
 *
 * One fraction for every meter rather than a number each. They are all "this
 * ends badly at 100%" and inventing a separate threshold per meter would be
 * more numbers to defend and keep in step.
 */
export const METER_ALERT_FRACTION = 0.75;

export type MeterBasis = "counted" | "estimated" | "dashboard";

/** Every meter is in bytes. */
export interface Meter {
  name: string;
  basis: MeterBasis;
  /** Null on a `dashboard` meter, which is the whole of what makes it one. */
  used: number | null;
  allowance: number;
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

/** A scalar bigint RPC's answer, or null when the call failed. Null is not
 *  zero: a meter that read a broken query as an empty store would report full
 *  headroom at exactly the moment nobody can check. */
async function readBigint(
  query: PromiseLike<{ data: unknown; error: unknown }>,
): Promise<number | null> {
  const { data, error } = await query;
  return error ? null : Number(data ?? 0);
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
 * Wants the secret key. Both functions are granted to nothing else, and the
 * counts have to include pending and rejected rows, which
 * `asset_read_approved` hides.
 */
export async function fetchMeters(
  supabase: SupabaseClient,
  now: Date = new Date(),
): Promise<MeterReport> {
  const [usage, bucketBytes] = await Promise.all([
    supabase.rpc("asset_storage_usage"),
    readBigint(supabase.rpc("staged_pictures_bucket_bytes")),
  ]);

  if (usage.error) throw new Error(`Could not read what the stores hold: ${usage.error.message}`);

  const rows = (usage.data ?? []) as unknown as UsageRow[];
  const durable = durableClasses(rows);

  return {
    at: now.toISOString(),
    durable,
    meters: [
      {
        name: "Supabase Storage",
        basis: "counted",
        used: bucketBytes,
        allowance: SUPABASE_STORAGE_ALLOWANCE_BYTES,
        note:
          "Every object in the staged-pictures bucket, summed by storage.get_size_by_bucket(). " +
          "Every upload counts here whatever its moderation state: a rejected picture (#348) is " +
          "still bytes in the bucket until somebody decides what happens to it.",
      },
      {
        name: "Supabase egress this month",
        basis: "dashboard",
        used: null,
        allowance: SUPABASE_EGRESS_ALLOWANCE_BYTES,
        note:
          "5 GB cached and 5 GB uncached, shared by the database, auth and storage across the " +
          "whole organisation and not just this project. /assets/staged/ reads a staged picture " +
          "out of the bucket whenever Vercel's CDN has no copy, which includes the first view " +
          "after every deployment, and the CDN answers the rest. Not exposed to the " +
          "application. Read it off the project dashboard.",
      },
      {
        name: `Vercel fast data transfer, last ${VERCEL_WINDOW_DAYS} days`,
        basis: "dashboard",
        used: null,
        allowance: VERCEL_FAST_DATA_TRANSFER_ALLOWANCE_BYTES,
        note:
          "Everything the site serves, including every view of a staged picture, since those " +
          "come through /assets/staged/ rather than straight from a store. Measured by the " +
          "platform and not exposed to the application. Read it off the project dashboard.",
      },
      {
        name: "Durable tier published size",
        basis: "estimated",
        used: total(rows, "static"),
        allowance: PAGES_PUBLISHED_ALLOWANCE_BYTES,
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
        `${meter.name}: ${formatBytes(meter.used ?? 0)} of ${formatBytes(meter.allowance)}, which is past ` +
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

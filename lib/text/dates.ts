/**
 * How the hub writes dates (#451). The locale and time zone are fixed so the
 * server and every browser print the same string for the same instant: a
 * cached page and its hydration agree, whoever reads it.
 */

/** A date older than this many days is written in full, not as "3 days ago". */
export const RELATIVE_CUTOFF_DAYS = 30;

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

const dayFormat = new Intl.DateTimeFormat("en-GB", {
  day: "numeric",
  month: "short",
  year: "numeric",
  timeZone: "UTC",
});

const timeFormat = new Intl.DateTimeFormat("en-GB", {
  hour: "2-digit",
  minute: "2-digit",
  hourCycle: "h23",
  timeZone: "UTC",
});

const relativeFormat = new Intl.RelativeTimeFormat("en-GB", { numeric: "always" });

function parse(value: string | Date): Date | null {
  const date = value instanceof Date ? value : new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * "9 Aug 2026" in UTC, or "9 Aug 2026, 14:05" with `time`. A value that is not
 * a date gives an empty string.
 */
export function formatDate(value: string | Date, options: { time?: boolean } = {}): string {
  const date = parse(value);
  if (date === null) return "";
  const day = dayFormat.format(date);
  return options.time ? `${day}, ${timeFormat.format(date)}` : day;
}

/**
 * "just now", "5 minutes ago", "3 hours ago" or "12 days ago" for a date inside
 * the cutoff, and the full date beyond it. `now` is passed in so the result
 * depends on nothing but the arguments. A date ahead of `now`, from clock skew,
 * reads "just now".
 */
export function formatRelative(value: string | Date, now: number | Date): string {
  const date = parse(value);
  if (date === null) return "";
  const age = (now instanceof Date ? now.getTime() : now) - date.getTime();

  if (age < MINUTE) return "just now";
  if (age < HOUR) return relativeFormat.format(-Math.floor(age / MINUTE), "minute");
  if (age < DAY) return relativeFormat.format(-Math.floor(age / HOUR), "hour");
  if (age < RELATIVE_CUTOFF_DAYS * DAY) {
    return relativeFormat.format(-Math.floor(age / DAY), "day");
  }
  return formatDate(date);
}

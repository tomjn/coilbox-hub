"use client";

import { useSyncExternalStore } from "react";
import { formatDate, formatRelative } from "@/lib/text/dates";

const noSubscription = () => () => {};

/** The current minute, or null on the server and during hydration. It holds
 *  still within a minute so React sees the same value on every read. */
function minuteNow(): number {
  return Math.floor(Date.now() / 60_000) * 60_000;
}

/**
 * A date as a `<time>` element. The server and the first client render write
 * the full date, so cached HTML is always true and hydration matches. Once
 * mounted, a recent date switches to "3 days ago". It does not tick. The
 * title holds the exact time for anyone who hovers.
 */
export function DateTime({ value }: { value: string }) {
  const now = useSyncExternalStore(noSubscription, minuteNow, () => null);
  const full = formatDate(value);

  return (
    <time dateTime={value} title={`${formatDate(value, { time: true })} UTC`}>
      {now === null ? full : formatRelative(value, now)}
    </time>
  );
}

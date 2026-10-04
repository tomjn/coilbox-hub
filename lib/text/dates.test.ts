import { expect, test } from "bun:test";
import { RELATIVE_CUTOFF_DAYS, formatDate, formatRelative } from "./dates";

const NOW = Date.parse("2026-08-09T12:00:00.000Z");
const ago = (ms: number) => new Date(NOW - ms).toISOString();
const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

test("formatDate writes day, short month and year in UTC", () => {
  expect(formatDate("2026-08-09T12:00:00.000Z")).toBe("9 Aug 2026");
});

test("formatDate uses UTC, not the machine's zone, near midnight", () => {
  expect(formatDate("2026-08-09T23:59:59.000Z")).toBe("9 Aug 2026");
  expect(formatDate("2026-08-10T00:00:00.000Z")).toBe("10 Aug 2026");
});

test("formatDate can add the time of day", () => {
  expect(formatDate("2026-08-09T14:05:00.000Z", { time: true })).toBe("9 Aug 2026, 14:05");
  expect(formatDate("2026-08-09T00:07:00.000Z", { time: true })).toBe("9 Aug 2026, 00:07");
});

test("formatDate gives an empty string for an invalid date", () => {
  expect(formatDate("not a date")).toBe("");
});

test("under a minute is just now", () => {
  expect(formatRelative(ago(0), NOW)).toBe("just now");
  expect(formatRelative(ago(MINUTE - 1), NOW)).toBe("just now");
});

test("a minute and up counts minutes", () => {
  expect(formatRelative(ago(MINUTE), NOW)).toBe("1 minute ago");
  expect(formatRelative(ago(5 * MINUTE), NOW)).toBe("5 minutes ago");
  expect(formatRelative(ago(59 * MINUTE), NOW)).toBe("59 minutes ago");
});

test("59 minutes against an hour", () => {
  expect(formatRelative(ago(HOUR - 1), NOW)).toBe("59 minutes ago");
  expect(formatRelative(ago(HOUR), NOW)).toBe("1 hour ago");
});

test("23 hours against a day", () => {
  expect(formatRelative(ago(23 * HOUR), NOW)).toBe("23 hours ago");
  expect(formatRelative(ago(DAY - 1), NOW)).toBe("23 hours ago");
  expect(formatRelative(ago(DAY), NOW)).toBe("1 day ago");
});

test("days run up to the cutoff, then the full date", () => {
  expect(RELATIVE_CUTOFF_DAYS).toBe(30);
  expect(formatRelative(ago(29 * DAY), NOW)).toBe("29 days ago");
  expect(formatRelative(ago(30 * DAY - 1), NOW)).toBe("29 days ago");
  expect(formatRelative(ago(30 * DAY), NOW)).toBe("10 Jul 2026");
  expect(formatRelative(ago(200 * DAY), NOW)).toBe("21 Jan 2026");
});

test("a date in the future reads just now", () => {
  expect(formatRelative(new Date(NOW + 3 * MINUTE).toISOString(), NOW)).toBe("just now");
  expect(formatRelative(new Date(NOW + 400 * DAY).toISOString(), NOW)).toBe("just now");
});

test("an invalid date gives an empty string", () => {
  expect(formatRelative("nonsense", NOW)).toBe("");
});

test("now can be a Date", () => {
  expect(formatRelative(ago(2 * HOUR), new Date(NOW))).toBe("2 hours ago");
});

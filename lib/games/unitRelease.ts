/**
 * A unit at a past release is addressed as `<unit>@<release>` in the unit's own
 * path segment, so the page has one param. Vercel only upgrades an unlisted
 * page to a whole static page when every param above it was listed at build,
 * and units are not listed, so a release as a deeper segment ran a function on
 * every view.
 *
 * Both halves are percent-encoded, which turns any `@` in them into `%40`, so
 * the only literal `@` is the separator.
 */

/** The path segment for `unit` at `release`, or for its latest facts. */
export function unitSegment(unit: string, release?: string): string {
  const name = encodeURIComponent(unit);
  return release ? `${name}@${encodeURIComponent(release)}` : name;
}

/** The unit and release a segment names. Next hands params over undecoded. */
export function readUnitSegment(segment: string): { unit: string; release?: string } {
  const at = segment.indexOf("@");
  if (at === -1) return { unit: decode(segment) };
  return { unit: decode(segment.slice(0, at)), release: decode(segment.slice(at + 1)) };
}

/** A segment that is not valid percent-encoding is read as it stands, which
 *  finds no unit and renders as not found. */
function decode(part: string): string {
  try {
    return decodeURIComponent(part);
  } catch {
    return part;
  }
}

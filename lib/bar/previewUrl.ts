/**
 * Resizing and reformatting BAR's preview thumbnails.
 *
 * `images.preview` is a thumbor URL: a fitted size and a filter list in the
 * path, then the source image. Both parts are ours to change, which is worth
 * doing twice over. The list hands out 1024 square webp at around 200KB, where
 * the page shows the image at a third of that width, and the share card cannot
 * read webp at all because Satori only decodes png and jpeg.
 *
 * Rewriting a URL owned by somebody else is only safe if it fails visibly, so
 * both functions match the exact shape they expect and give up rather than
 * guess. Resizing returns the URL untouched on a miss, since the original still
 * works. Asking for jpeg returns null, since a webp in the share card would
 * render as a blank panel.
 */

/** `/i/fit-in/<width>x<height>/filters:<list>/<source>`. */
const THUMBOR = /^(.*\/i\/fit-in\/)\d+x\d+(\/filters:)([^/]*)(\/.*)$/;

/** The same thumbnail at `size` square, or the original when the URL is not
 * the shape this knows how to edit. */
export function previewAtSize(url: string, size: number): string {
  const parts = url.match(THUMBOR);
  if (!parts) return url;
  const [, head, filters, list, tail] = parts;
  return `${head}${size}x${size}${filters}${list}${tail}`;
}

/** The same thumbnail as jpeg at `size` square, or null when the URL cannot be
 * rewritten and so cannot be trusted to decode. */
export function previewAsJpeg(url: string, size: number): string | null {
  const parts = url.match(THUMBOR);
  if (!parts) return null;
  const [, head, filters, list, tail] = parts;
  const rewritten = list.includes("format(")
    ? list.replace(/format\([^)]*\)/, "format(jpeg)")
    : [list, "format(jpeg)"].filter(Boolean).join(":");
  return `${head}${size}x${size}${filters}${rewritten}${tail}`;
}

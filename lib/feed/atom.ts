/**
 * An Atom feed (RFC 4287) written as a string.
 *
 * Everything that reaches the document goes through {@link escapeText} or
 * {@link escapeAttribute}, because titles, descriptions and author names are
 * written by whoever published the item. They are put in as escaped text and
 * never as markup, so a description can say `<script>` and a feed reader shows
 * those characters.
 */

export interface AtomEntry {
  /** Permanent. It must not change when the item is edited or the site moves. */
  id: string;
  title: string;
  /** The page for this entry. */
  link: string;
  published: string;
  updated: string;
  /** Null or blank leaves the author to the feed's own. */
  author: string | null;
  /** Plain text. Blank leaves the element out. */
  summary: string;
  category: { term: string; label: string };
}

export interface AtomFeed {
  id: string;
  title: string;
  subtitle: string;
  selfUrl: string;
  alternateUrl: string;
  /** The newest entry's time. Not the time the feed was built. */
  updated: string;
  /** The author for entries that have none of their own. */
  author: string;
  entries: AtomEntry[];
}

/** Anything XML 1.0 does not allow in a document: control characters other than
 *  tab, newline and carriage return, lone surrogates, and U+FFFE and U+FFFF. */
const ILLEGAL_XML = /[^\t\n\r\u0020-\uD7FF\uE000-\uFFFD\u{10000}-\u{10FFFF}]/gu;

/** Text for an element body. `]]>` cannot survive because `>` is escaped. */
export function escapeText(text: string): string {
  return text
    .replace(ILLEGAL_XML, "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&apos;");
}

/** Text for an attribute value. A parser turns a raw tab or line break in an
 *  attribute into a space, so they go out as character references. */
export function escapeAttribute(text: string): string {
  return escapeText(text).replace(/\t/g, "&#9;").replace(/\n/g, "&#10;").replace(/\r/g, "&#13;");
}

/** A timestamp as RFC 3339 in UTC. Throws on a value that is not a date, so a
 *  bad row stops the feed rather than carrying a made up time. */
export function rfc3339(value: string): string {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) throw new Error(`Not a date: ${value}`);
  return date.toISOString();
}

function author(name: string): string {
  return `<author><name>${escapeText(name)}</name></author>`;
}

function entry(item: AtomEntry): string {
  const name = (item.author ?? "").replace(ILLEGAL_XML, "").trim();
  const summary = item.summary.replace(ILLEGAL_XML, "").trim() ? escapeText(item.summary) : "";
  return [
    "<entry>",
    `<id>${escapeText(item.id)}</id>`,
    `<title type="text">${escapeText(item.title)}</title>`,
    `<link rel="alternate" type="text/html" href="${escapeAttribute(item.link)}"/>`,
    `<published>${rfc3339(item.published)}</published>`,
    `<updated>${rfc3339(item.updated)}</updated>`,
    name ? author(name) : "",
    `<category term="${escapeAttribute(item.category.term)}" label="${escapeAttribute(item.category.label)}"/>`,
    summary ? `<summary type="text">${summary}</summary>` : "",
    "</entry>",
  ]
    .filter(Boolean)
    .join("\n");
}

export function atomDocument(feed: AtomFeed): string {
  return (
    [
      '<?xml version="1.0" encoding="utf-8"?>',
      '<feed xmlns="http://www.w3.org/2005/Atom">',
      `<id>${escapeText(feed.id)}</id>`,
      `<title type="text">${escapeText(feed.title)}</title>`,
      `<subtitle type="text">${escapeText(feed.subtitle)}</subtitle>`,
      `<updated>${rfc3339(feed.updated)}</updated>`,
      `<link rel="self" type="application/atom+xml" href="${escapeAttribute(feed.selfUrl)}"/>`,
      `<link rel="alternate" type="text/html" href="${escapeAttribute(feed.alternateUrl)}"/>`,
      author(feed.author),
      ...feed.entries.map(entry),
      "</feed>",
    ].join("\n") + "\n"
  );
}

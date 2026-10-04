import { expect, test } from "bun:test";
import { type AtomEntry, type AtomFeed, atomDocument, escapeAttribute, escapeText, rfc3339 } from "./atom";

const ENTRY: AtomEntry = {
  id: "urn:uuid:11111111-1111-4111-8111-111111111111",
  title: "Fast start",
  link: "https://hub.example/item/11111111-1111-4111-8111-111111111111",
  published: "2026-10-01T10:00:00.000Z",
  updated: "2026-10-02T10:00:00.000Z",
  author: "Ada",
  summary: "Opens with two builders.",
  category: { term: "preset", label: "Preset" },
};

const FEED: AtomFeed = {
  id: "https://hub.example/feed.xml",
  title: "Coilbox Hub",
  subtitle: "New things people made in Coilbox.",
  selfUrl: "https://hub.example/feed.xml",
  alternateUrl: "https://hub.example/gallery",
  updated: "2026-10-02T10:00:00.000Z",
  author: "Coilbox Hub",
  entries: [ENTRY],
};

/** The entry elements of a document, as text. */
function entriesOf(xml: string): string[] {
  return [...xml.matchAll(/<entry>[\s\S]*?<\/entry>/g)].map((m) => m[0]);
}

/** Every `<` that opens something, to check a document nests properly. */
function tagNames(xml: string): string[] {
  return [...xml.matchAll(/<(\/?)([A-Za-z][\w:-]*)[^>]*?(\/?)>/g)].flatMap(([, close, name, self]) =>
    self ? [] : [close ? `/${name}` : name],
  );
}

/** A stack check, standing in for a parser: bun has no XML parser and the
 *  dependency tree holds none. Every opened element must close in order. */
function expectBalanced(xml: string) {
  const stack: string[] = [];
  for (const name of tagNames(xml.replace(/<\?[\s\S]*?\?>/, ""))) {
    if (name.startsWith("/")) expect(stack.pop()).toBe(name.slice(1));
    else stack.push(name);
  }
  expect(stack).toEqual([]);
}

test("text escapes ampersands, angle brackets and quotes", () => {
  expect(escapeText(`Tom & Jerry <b>"hi"</b> it's`)).toBe(
    "Tom &amp; Jerry &lt;b&gt;&quot;hi&quot;&lt;/b&gt; it&apos;s",
  );
});

test("an ampersand is escaped once, not twice", () => {
  expect(escapeText("&amp;")).toBe("&amp;amp;");
});

test("a closing CDATA marker in a title cannot survive", () => {
  const out = escapeText("a ]]> b");
  expect(out).not.toContain("]]>");
  expect(out).toBe("a ]]&gt; b");
});

test("characters illegal in XML 1.0 are removed", () => {
  expect(escapeText("a\u0000b\u0008c\u000Bd\u000Ce\u001Ff\uFFFEg\uFFFFh")).toBe("abcdefgh");
});

test("a lone surrogate is removed and a pair is kept", () => {
  expect(escapeText("a\uD800b")).toBe("ab");
  expect(escapeText("a\uDC00b")).toBe("ab");
  expect(escapeText("rocket 🚀")).toBe("rocket 🚀");
});

test("tab, newline and carriage return stay in text", () => {
  expect(escapeText("a\tb\nc\rd")).toBe("a\tb\nc\rd");
});

test("non ASCII text passes through unchanged", () => {
  expect(escapeText("Café Ünïcode 日本語")).toBe("Café Ünïcode 日本語");
});

test("an attribute keeps its quotes from ending the value", () => {
  const out = escapeAttribute(`a "quoted" 'word' & <tag>`);
  expect(out).not.toMatch(/["'<>]/);
  expect(out).toBe("a &quot;quoted&quot; &apos;word&apos; &amp; &lt;tag&gt;");
});

test("an attribute keeps a line break from turning into a space", () => {
  expect(escapeAttribute("a\nb\tc\rd")).toBe("a&#10;b&#9;c&#13;d");
});

test("a date becomes RFC 3339 in UTC", () => {
  expect(rfc3339("2026-10-04T12:30:00+02:00")).toBe("2026-10-04T10:30:00.000Z");
  expect(rfc3339("2026-10-04T12:30:00.123456+00:00")).toBe("2026-10-04T12:30:00.123Z");
});

test("a date that does not parse is an error, not a made up time", () => {
  expect(() => rfc3339("not a date")).toThrow();
});

test("the document declares XML and the Atom namespace", () => {
  const xml = atomDocument(FEED);
  expect(xml.startsWith('<?xml version="1.0" encoding="utf-8"?>\n<feed xmlns="http://www.w3.org/2005/Atom">')).toBe(true);
  expect(xml.trimEnd().endsWith("</feed>")).toBe(true);
});

test("the feed carries id, title, updated, a self link and an author", () => {
  const xml = atomDocument(FEED);
  const head = xml.slice(0, xml.indexOf("<entry>"));
  expect(head).toContain("<id>https://hub.example/feed.xml</id>");
  expect(head).toContain('<title type="text">Coilbox Hub</title>');
  expect(head).toContain("<updated>2026-10-02T10:00:00.000Z</updated>");
  expect(head).toContain('<link rel="self" type="application/atom+xml" href="https://hub.example/feed.xml"/>');
  expect(head).toContain('<link rel="alternate" type="text/html" href="https://hub.example/gallery"/>');
  expect(head).toContain("<author><name>Coilbox Hub</name></author>");
});

test("an entry carries id, title, times, author, link, summary and category", () => {
  const [entry] = entriesOf(atomDocument(FEED));
  expect(entry).toContain("<id>urn:uuid:11111111-1111-4111-8111-111111111111</id>");
  expect(entry).toContain('<title type="text">Fast start</title>');
  expect(entry).toContain("<published>2026-10-01T10:00:00.000Z</published>");
  expect(entry).toContain("<updated>2026-10-02T10:00:00.000Z</updated>");
  expect(entry).toContain("<author><name>Ada</name></author>");
  expect(entry).toContain(
    '<link rel="alternate" type="text/html" href="https://hub.example/item/11111111-1111-4111-8111-111111111111"/>',
  );
  expect(entry).toContain('<summary type="text">Opens with two builders.</summary>');
  expect(entry).toContain('<category term="preset" label="Preset"/>');
});

test("user text is escaped as text and never becomes markup", () => {
  const xml = atomDocument({
    ...FEED,
    entries: [
      {
        ...ENTRY,
        title: "<script>alert(1)</script> & ]]>",
        summary: '<img src=x onerror="boom"> &lt;already&gt;',
        author: "Mallory </name><name>x",
      },
    ],
  });
  const [entry] = entriesOf(xml);
  expect(entry).not.toContain("<script>");
  expect(entry).not.toContain("<img");
  expect(entry).not.toContain("]]>");
  expect(entry).toContain("&lt;script&gt;alert(1)&lt;/script&gt; &amp; ]]&gt;");
  expect(entry).toContain("&lt;img src=x onerror=&quot;boom&quot;&gt; &amp;lt;already&amp;gt;");
  expect(entry).toContain("<author><name>Mallory &lt;/name&gt;&lt;name&gt;x</name></author>");
  expectBalanced(xml);
});

test("quotes in a link or category cannot break out of the attribute", () => {
  const xml = atomDocument({
    ...FEED,
    entries: [
      {
        ...ENTRY,
        link: 'https://hub.example/item/1" onload="x',
        category: { term: 'a"b', label: "c'd" },
      },
    ],
  });
  const [entry] = entriesOf(xml);
  expect(entry).toContain('href="https://hub.example/item/1&quot; onload=&quot;x"');
  expect(entry).toContain('<category term="a&quot;b" label="c&apos;d"/>');
  expectBalanced(xml);
});

test("an emoji and other non ASCII text survive in a title", () => {
  const [entry] = entriesOf(atomDocument({ ...FEED, entries: [{ ...ENTRY, title: "Rocket 🚀 café 日本" }] }));
  expect(entry).toContain('<title type="text">Rocket 🚀 café 日本</title>');
});

test("control characters never reach the document", () => {
  const xml = atomDocument({ ...FEED, entries: [{ ...ENTRY, title: "a\u0000b", summary: "c\u0001d" }] });
  expect(xml).not.toMatch(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/);
  expect(xml).toContain(">ab<");
  expect(xml).toContain(">cd<");
});

test("an empty description leaves the summary out", () => {
  const [entry] = entriesOf(atomDocument({ ...FEED, entries: [{ ...ENTRY, summary: "" }] }));
  expect(entry).not.toContain("<summary");
  expect(entry).toContain("<link rel=\"alternate\"");
});

test("a description of only control characters counts as empty", () => {
  const [entry] = entriesOf(atomDocument({ ...FEED, entries: [{ ...ENTRY, summary: "\u0000\u0001" }] }));
  expect(entry).not.toContain("<summary");
});

test("a missing author name leaves the author to the feed", () => {
  for (const author of [null, "", "   "]) {
    const [entry] = entriesOf(atomDocument({ ...FEED, entries: [{ ...ENTRY, author }] }));
    expect(entry).not.toContain("<author");
  }
});

test("an empty feed is a valid feed with no entries", () => {
  const xml = atomDocument({ ...FEED, entries: [] });
  expect(entriesOf(xml)).toEqual([]);
  expect(xml).toContain("<updated>2026-10-02T10:00:00.000Z</updated>");
  expectBalanced(xml);
});

test("entries keep the order they were given", () => {
  const second = { ...ENTRY, id: "urn:uuid:22222222-2222-4222-8222-222222222222", title: "Second" };
  const entries = entriesOf(atomDocument({ ...FEED, entries: [ENTRY, second] }));
  expect(entries).toHaveLength(2);
  expect(entries[0]).toContain("Fast start");
  expect(entries[1]).toContain("Second");
});

test("a full document is balanced", () => {
  expectBalanced(atomDocument({ ...FEED, entries: [ENTRY, { ...ENTRY, id: "urn:uuid:x", author: null, summary: "" }] }));
});

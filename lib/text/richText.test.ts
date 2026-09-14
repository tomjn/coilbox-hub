import { expect, test } from "bun:test";
import { parseInline, parseRichText, richTextInline, richTextToPlainText } from "./richText";

/**
 * The parser behind a game's description (#357): paragraphs, line breaks,
 * `**bold**` and `*italic*`/`_italic_`, and nothing else. Every edge case here
 * is one the issue calls out by name.
 */

test("two paragraphs split on a blank line", () => {
  expect(parseRichText("First.\n\nSecond.")).toEqual([
    [[{ type: "text", value: "First." }]],
    [[{ type: "text", value: "Second." }]],
  ]);
});

test("a single line break stays inside the paragraph as a second line", () => {
  expect(parseRichText("First line\nSecond line")).toEqual([
    [
      [{ type: "text", value: "First line" }],
      [{ type: "text", value: "Second line" }],
    ],
  ]);
});

test("three or more newlines collapse to one paragraph break", () => {
  expect(parseRichText("First.\n\n\n\nSecond.")).toEqual(parseRichText("First.\n\nSecond."));
});

test("windows line endings normalise like a plain newline", () => {
  expect(parseRichText("First.\r\n\r\nSecond.")).toEqual(parseRichText("First.\n\nSecond."));
  expect(parseRichText("First line\r\nSecond line")).toEqual(parseRichText("First line\nSecond line"));
});

test("leading and trailing whitespace is dropped", () => {
  expect(parseRichText("  \n\n  Hello  \n\n  ")).toEqual([[[{ type: "text", value: "Hello" }]]]);
});

test("bold and italic parse to their own node", () => {
  expect(parseInline("**bold**")).toEqual([{ type: "bold", children: [{ type: "text", value: "bold" }] }]);
  expect(parseInline("*italic*")).toEqual([{ type: "italic", children: [{ type: "text", value: "italic" }] }]);
  expect(parseInline("_italic_")).toEqual([{ type: "italic", children: [{ type: "text", value: "italic" }] }]);
});

test("an unclosed marker stays literal", () => {
  expect(parseInline("**bold with no close")).toEqual([
    { type: "text", value: "**bold with no close" },
  ]);
  expect(parseInline("*italic with no close")).toEqual([
    { type: "text", value: "*italic with no close" },
  ]);
  expect(parseInline("_italic with no close")).toEqual([
    { type: "text", value: "_italic with no close" },
  ]);
});

test("** bolds even mid word", () => {
  expect(parseInline("wo**rd**s")).toEqual([
    { type: "text", value: "wo" },
    { type: "bold", children: [{ type: "text", value: "rd" }] },
    { type: "text", value: "s" },
  ]);
});

test("underscores inside an identifier do not italicise", () => {
  expect(parseInline("snake_case_name")).toEqual([{ type: "text", value: "snake_case_name" }]);
});

test("an underscore at a word boundary still italicises", () => {
  expect(parseInline("say _hello_ now")).toEqual([
    { type: "text", value: "say " },
    { type: "italic", children: [{ type: "text", value: "hello" }] },
    { type: "text", value: " now" },
  ]);
});

test("bold nests inside italic", () => {
  expect(parseInline("*italic **bold** still italic*")).toEqual([
    {
      type: "italic",
      children: [
        { type: "text", value: "italic " },
        { type: "bold", children: [{ type: "text", value: "bold" }] },
        { type: "text", value: " still italic" },
      ],
    },
  ]);
});

test("bold nests inside underscore italic too", () => {
  expect(parseInline("_italic **bold** still italic_")).toEqual([
    {
      type: "italic",
      children: [
        { type: "text", value: "italic " },
        { type: "bold", children: [{ type: "text", value: "bold" }] },
        { type: "text", value: " still italic" },
      ],
    },
  ]);
});

test("script tags and entities are literal text, not parsed", () => {
  expect(parseInline("<script>alert(1)</script> & co")).toEqual([
    { type: "text", value: "<script>alert(1)</script> & co" },
  ]);
});

test("plain text strips every mark and joins breaks with a space", () => {
  expect(richTextToPlainText("**Bold** and *italic*.\n\nSecond paragraph.")).toBe(
    "Bold and italic. Second paragraph.",
  );
  expect(richTextToPlainText("Line one\nLine two")).toBe("Line one Line two");
});

test("plain text of an empty description is an empty string", () => {
  expect(richTextToPlainText("")).toBe("");
  expect(richTextToPlainText("   \n\n  ")).toBe("");
});

test("inline flattening keeps formatting but drops paragraph and line breaks", () => {
  expect(richTextInline("**Bold**\n\nSecond *italic* paragraph")).toEqual([
    { type: "bold", children: [{ type: "text", value: "Bold" }] },
    { type: "text", value: " " },
    { type: "text", value: "Second " },
    { type: "italic", children: [{ type: "text", value: "italic" }] },
    { type: "text", value: " paragraph" },
  ]);
});

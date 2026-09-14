import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { RichText, RichTextInline } from "@/components/RichText";

/**
 * The React side of #357: the parsed tree becomes real elements, never a
 * string of HTML, so a description cannot inject markup of its own.
 */

test("two paragraphs become two <p> elements", () => {
  const html = renderToStaticMarkup(<RichText text={"First.\n\nSecond."} />);
  expect(html).toContain("<p>First.</p>");
  expect(html).toContain("<p>Second.</p>");
});

test("a single line break becomes a <br> inside one paragraph", () => {
  const html = renderToStaticMarkup(<RichText text={"First line\nSecond line"} />);
  expect(html).toContain("First line<br/>Second line");
  expect(html.match(/<p/g)).toHaveLength(1);
});

test("bold and italic render as real elements", () => {
  const html = renderToStaticMarkup(<RichText text="**bold** and *italic* and _also italic_" />);
  expect(html).toContain("<strong>bold</strong>");
  expect(html).toContain("<em>italic</em>");
  expect(html).toContain("<em>also italic</em>");
});

test("a script tag shows as literal escaped text, not a real element", () => {
  const html = renderToStaticMarkup(<RichText text="<script>alert(1)</script>" />);
  expect(html).not.toContain("<script>alert(1)</script>");
  expect(html).toContain("&lt;script&gt;alert(1)&lt;/script&gt;");
});

test("an empty description renders nothing", () => {
  expect(renderToStaticMarkup(<RichText text="" />)).toBe("");
});

test("the className lands on the paragraph", () => {
  const html = renderToStaticMarkup(<RichText text="Hello" className="max-w-3xl text-neutral-300" />);
  expect(html).toContain('<p class="max-w-3xl text-neutral-300">Hello</p>');
});

test("inline mode keeps formatting but never breaks a line of its own", () => {
  const html = renderToStaticMarkup(<RichTextInline text={"**Bold**\n\nSecond paragraph"} />);
  expect(html).not.toContain("<p");
  expect(html).not.toContain("<br");
  expect(html).toContain("<strong>Bold</strong>");
  expect(html).toContain("Second paragraph");
});

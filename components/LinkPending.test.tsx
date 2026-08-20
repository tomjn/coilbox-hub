import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { LinkPending } from "@/components/LinkPending";

test("outside a navigation the body is rendered as it was given", () => {
  const html = renderToStaticMarkup(
    <LinkPending className="flex gap-2">Gallery</LinkPending>,
  );
  expect(html).toBe('<span class="flex gap-2 transition-opacity">Gallery</span>');
});

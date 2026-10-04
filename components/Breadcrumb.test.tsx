import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { Breadcrumb } from "@/components/Breadcrumb";

const crumbs = [
  { label: "Games", href: "/games" },
  { label: "Tab Test Game", href: "/games/tabtest" },
  { label: "Units" },
];

test("names the landmark and marks only the last crumb as the current page", () => {
  const html = renderToStaticMarkup(<Breadcrumb crumbs={crumbs} />);
  expect(html).toContain('aria-label="Breadcrumb"');
  expect(html.match(/aria-current="page"/g)).toHaveLength(1);
  expect(html).toMatch(/<span[^>]*aria-current="page"[^>]*>Units<\/span>/);
});

test("links every crumb but the last, to the href it was given", () => {
  const html = renderToStaticMarkup(<Breadcrumb crumbs={crumbs} />);
  expect(html.match(/href="[^"]*"/g)).toEqual(['href="/games"', 'href="/games/tabtest"']);
});

test("keeps the full label in a title so a clipped name can still be read", () => {
  const long = "A".repeat(200);
  const html = renderToStaticMarkup(<Breadcrumb crumbs={[{ label: long, href: "/x" }, { label: "Units" }]} />);
  expect(html).toContain(`title="${long}"`);
  expect(html).toContain("truncate");
});

test("hides the separators from assistive technology", () => {
  const html = renderToStaticMarkup(<Breadcrumb crumbs={crumbs} />);
  expect(html.match(/<span aria-hidden="true"> \/ <\/span>/g)).toHaveLength(2);
});

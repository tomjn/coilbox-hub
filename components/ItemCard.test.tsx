import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { ItemCard } from "@/components/ItemCard";
import { type ItemSummary, parseFilters } from "@/lib/gallery/query";

const ADA = "0f8fad5b-0449-4000-8000-0000000000aa";

const ITEM: ItemSummary = {
  id: "11111111-1111-1111-1111-111111111111",
  kind: "preset",
  mode: null,
  title: "A cautious opening",
  description: "",
  game_name: null,
  game_key: null,
  map_name: null,
  tags: [],
  author_name: "Ada Q",
  author_id: ADA,
  created_at: "2026-08-01T00:00:00Z",
  featured_at: null,
};

function render(item: ItemSummary): string {
  return renderToStaticMarkup(
    <ItemCard item={item} filters={parseFilters({ author: "Old" })} origin="https://hub.test" />,
  );
}

function authorHref(html: string): string | undefined {
  return html.match(/href="([^"]*)">Ada Q</)?.[1];
}

test("the author link goes to the account, and drops an author name filter", () => {
  const html = render(ITEM);
  expect(authorHref(html)).toBe(`/gallery?account=${ADA}`);
});

test("with no author id the author link falls back to the name", () => {
  const html = render({ ...ITEM, author_id: null });
  expect(authorHref(html)).toBe("/gallery?author=Ada+Q");
});

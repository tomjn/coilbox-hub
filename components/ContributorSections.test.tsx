import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { ContributorSections } from "@/components/ContributorSections";
import type { Contributions } from "@/lib/profile/contributions";

const ACCOUNT = "c0000000-0000-0000-0000-000000000001";

const FULL: Contributions = {
  name: "Zyx Contributor",
  maps: {
    count: 2,
    recent: [
      { slug: "zyx-one", label: "Zyx One" },
      { slug: "zyx-two", label: "Zyx Two" },
    ],
  },
  pictures: { count: 5 },
  games: { count: 1, recent: [{ shortname: "ZYX", label: "Zyx Game" }] },
  items: { count: 3, recent: [{ id: "i1", title: "A preset" }] },
};

const EMPTY: Contributions = {
  name: "Zyx Contributor",
  maps: { count: 0, recent: [] },
  pictures: { count: 0 },
  games: { count: 0, recent: [] },
  items: { count: 0, recent: [] },
};

function render(contributions: Contributions) {
  return renderToStaticMarkup(<ContributorSections accountId={ACCOUNT} contributions={contributions} />);
}

test("the map and game sections say the account sent facts, not that it made anything", () => {
  const html = render(FULL);
  expect(html).toContain("Sent the facts for");
  expect(html).not.toMatch(/\bmade\b/i);
});

test("each section links to the page it lists", () => {
  const html = render(FULL);
  expect(html).toContain('href="/map/zyx-one"');
  expect(html).toContain('href="/games/ZYX"');
  expect(html).toContain('href="/item/i1"');
  expect(html).toContain(`href="/gallery?account=${ACCOUNT}"`);
});

test("counts are shown with the right singular and plural", () => {
  const html = render({ ...FULL, maps: { count: 1, recent: FULL.maps.recent.slice(0, 1) } });
  expect(html).toContain("1 map");
  expect(html).not.toContain("1 maps");
  expect(html).toContain("5 pictures");
});

test("a kind with nothing in it says so and links nowhere", () => {
  const html = render(EMPTY);
  expect(html).toContain("No maps yet");
  expect(html).not.toContain("/gallery?account=");
  expect(html).not.toContain('href="/map/');
});

import { expect, test } from "bun:test";
import {
  buildItemBody,
  buildItemsListBody,
  ITEM_FORMAT,
  ITEM_VERSION,
  ITEMS_FORMAT,
  ITEMS_VERSION,
  parseApiFilters,
} from "./items";
import { PAGE_SIZE } from "@/lib/gallery/query";
import type { ItemSummary } from "@/lib/gallery/query";

const SUMMARY: ItemSummary = {
  id: "11111111-1111-1111-1111-111111111111",
  kind: "preset",
  mode: null,
  title: "apitest-example",
  description: "",
  game_name: "Beyond All Reason",
  map_name: null,
  tags: ["eco"],
  author_name: "Someone",
  created_at: "2026-01-01T00:00:00Z",
};

test("a list body carries the format marker, version and paging", () => {
  const body = buildItemsListBody([SUMMARY], 2, 30);

  expect(body.format).toBe(ITEMS_FORMAT);
  expect(body.version).toBe(ITEMS_VERSION);
  expect(body.version).toBe(1);
  expect(body.page).toBe(2);
  expect(body.page_size).toBe(PAGE_SIZE);
  expect(body.total).toBe(30);
  expect(body.items).toEqual([SUMMARY]);
});

test("an item body carries the format marker, version and a container URL rather than the container", () => {
  const body = buildItemBody(SUMMARY, "https://coilbox-hub.example/i/11111111-1111-1111-1111-111111111111");

  expect(body.format).toBe(ITEM_FORMAT);
  expect(body.version).toBe(ITEM_VERSION);
  expect(body.version).toBe(1);
  expect(body.item.container_url).toBe(
    "https://coilbox-hub.example/i/11111111-1111-1111-1111-111111111111",
  );
  expect(body.item.id).toBe(SUMMARY.id);
  expect(body.item).not.toHaveProperty("container");
});

test("recognised filters parse the same way the website's forgiving parser does", () => {
  const result = parseApiFilters(new URLSearchParams("kind=preset&game=BAR&page=2"));

  expect(result.ok).toBe(true);
  if (result.ok) {
    expect(result.filters.kind).toBe("preset");
    expect(result.filters.game).toBe("BAR");
    expect(result.filters.page).toBe(2);
  }
});

test("an unknown query parameter is rejected rather than silently dropped", () => {
  const result = parseApiFilters(new URLSearchParams("kind=preset&sort=newest"));

  expect(result.ok).toBe(false);
  if (!result.ok) {
    expect(result.error).toBe("Unknown query parameter: sort");
  }
});

test("an unrecognised kind is rejected rather than falling back to unfiltered", () => {
  const result = parseApiFilters(new URLSearchParams("kind=campaign"));

  expect(result.ok).toBe(false);
  if (!result.ok) {
    expect(result.error).toBe("Unknown kind: campaign");
  }
});

test("no filters at all is fine", () => {
  const result = parseApiFilters(new URLSearchParams(""));

  expect(result.ok).toBe(true);
  if (result.ok) {
    expect(result.filters.kind).toBeNull();
    expect(result.filters.page).toBe(1);
  }
});

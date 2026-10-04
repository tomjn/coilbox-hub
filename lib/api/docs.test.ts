import { expect, test } from "bun:test";
import { ITEMS_PARAMS } from "./docs";
import { FILTER_KEYS, parseApiFilters, SINGLE_VALUE_KEYS } from "./items";

test("the documented parameters of /api/v1/items are the ones the route accepts", () => {
  expect(ITEMS_PARAMS.map((param) => param.name).sort()).toEqual([...FILTER_KEYS].sort());
});

test("the parameters documented as repeatable are the ones the route lets repeat", () => {
  const single = ITEMS_PARAMS.filter((param) => !param.repeatable).map((param) => param.name);
  expect(single.sort()).toEqual([...SINGLE_VALUE_KEYS].sort());
});

test("the route accepts every documented parameter and rejects one that is not", () => {
  for (const param of ITEMS_PARAMS) {
    const values: Record<string, string> = { kind: "preset", sort: "title", page: "1" };
    const value = values[param.name] ?? "x";
    expect(parseApiFilters(new URLSearchParams({ [param.name]: value })).ok).toBe(true);
  }
  expect(parseApiFilters(new URLSearchParams({ undocumented: "x" })).ok).toBe(false);
});

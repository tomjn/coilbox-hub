import { expect, test } from "bun:test";
import { cardTitles } from "./cardTitles";

/**
 * Two cards on the same page reading as the same item shown twice (issue
 * #311). A title with a twin on the page gets a tail of its id, a title
 * without one is left alone.
 */

test("a title with no twin on the page keeps its own title, untouched", () => {
  const titles = cardTitles([
    { id: "11111111-1111-1111-1111-111111111111", title: "SF Double Cold Fusion" },
    { id: "22222222-2222-2222-2222-222222222222", title: "Something else entirely" },
  ]);

  expect(titles.get("11111111-1111-1111-1111-111111111111")).toEqual({
    title: "SF Double Cold Fusion",
    tail: null,
  });
});

test("a title shared by two rows gets a different tail on each", () => {
  const titles = cardTitles([
    { id: "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaab460ed", title: "SF Double Cold Fusion" },
    { id: "bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbabeed", title: "SF Double Cold Fusion" },
  ]);

  expect(titles.get("aaaaaaaa-aaaa-aaaa-aaaa-aaaaaab460ed")).toEqual({
    title: "SF Double Cold Fusion",
    tail: "b460ed",
  });
  expect(titles.get("bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbabeed")).toEqual({
    title: "SF Double Cold Fusion",
    tail: "babeed",
  });
});

test("a title shared by three rows gets a tail on all three, not just the second onward", () => {
  const items = [
    { id: "11111111-1111-1111-1111-111111111111", title: "Duplicate" },
    { id: "22222222-2222-2222-2222-222222222222", title: "Duplicate" },
    { id: "33333333-3333-3333-3333-333333333333", title: "Duplicate" },
  ];

  const titles = cardTitles(items);
  for (const item of items) {
    expect(titles.get(item.id)?.tail).not.toBeNull();
  }
});

test("titles are compared trimmed and case folded, so 'Foo' and 'foo ' are the same title", () => {
  const titles = cardTitles([
    { id: "11111111-1111-1111-1111-111111111111", title: "Foo" },
    { id: "22222222-2222-2222-2222-222222222222", title: "foo " },
  ]);

  expect(titles.get("11111111-1111-1111-1111-111111111111")?.tail).not.toBeNull();
  expect(titles.get("22222222-2222-2222-2222-222222222222")?.tail).not.toBeNull();
});

test("the tail is the last six characters of the id", () => {
  const titles = cardTitles([
    { id: "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaab460ed", title: "Duplicate" },
    { id: "bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbabeed", title: "Duplicate" },
  ]);

  expect(titles.get("aaaaaaaa-aaaa-aaaa-aaaa-aaaaaab460ed")?.tail).toBe("b460ed");
});

test("an empty page returns no titles", () => {
  expect(cardTitles([]).size).toBe(0);
});

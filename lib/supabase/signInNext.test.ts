import { expect, test } from "bun:test";
import { signInNext } from "@/lib/supabase/signInNext";

const ORIGIN = "https://hub.test";

test("takes the form's next when it is a path", () => {
  expect(signInNext("/item/1", `${ORIGIN}/maps`, ORIGIN)).toBe("/item/1");
});

test("falls back to the page the form was posted from, query and all", () => {
  expect(signInNext(null, `${ORIGIN}/maps?q=comet`, ORIGIN)).toBe("/maps?q=comet");
});

test("ignores a referer from another site", () => {
  expect(signInNext(null, "https://elsewhere.test/x", ORIGIN)).toBe("/");
});

test("refuses a protocol relative next and a full URL", () => {
  expect(signInNext("//elsewhere.test", null, ORIGIN)).toBe("/");
  expect(signInNext("https://elsewhere.test", null, ORIGIN)).toBe("/");
});

test("a referer that is not a URL is not a page", () => {
  expect(signInNext(null, "not a url", ORIGIN)).toBe("/");
});

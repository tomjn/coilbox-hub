import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { NavAccount, NavModeration } from "@/components/NavAccount";

// What the static page carries, before the browser has read any cookies.

test("the page carries the sign in button, hidden once the inline mark says signed in", () => {
  const html = renderToStaticMarkup(<NavAccount className="nav" />);
  expect(html).toContain('<span class="contents in-data-[session=in]:hidden"><form action="/auth/signin"');
  expect(html).toContain('<span class="hidden in-data-[session=in]:contents"><span aria-hidden="true"');
  expect(html).not.toContain("/auth/signout");
});

test("the page carries no moderation link", () => {
  expect(renderToStaticMarkup(<NavModeration className="nav" />)).toBe("");
});

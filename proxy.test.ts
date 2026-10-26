import { expect, test } from "bun:test";
// The helper's own file, not `next/experimental/testing/server`. That index
// also loads Next's request storage before `AsyncLocalStorage` is set up, and
// every later test file that touches it then fails. Importing `next/server`
// here does the same, which is why the cookie tests pass plain names.
import { unstable_doesMiddlewareMatch } from "next/dist/experimental/testing/server/middleware-testing-utils";
import nextConfig from "./next.config";
import { hasAuthCookie } from "./lib/auth/navCookie";
import { config } from "./proxy";

function runsOn(url: string): boolean {
  return unstable_doesMiddlewareMatch({ config, nextConfig, url });
}

test("the proxy runs on the pages that read the session on the server", () => {
  for (const url of [
    "/account",
    "/account/verify",
    "/publish",
    "/moderation",
    "/moderation/assets",
    "/ops",
    "/sign-in",
    "/item/abc/edit",
    "/item/abc/withdrawn",
    "/games/bar/edit",
    "/auth/callback",
    "/auth/signout",
    "/auth/nav",
    "/dev/sign-in",
  ]) {
    expect(runsOn(url)).toBe(true);
  }
});

test("the proxy skips every page the CDN can serve, and routes that never read a session cookie", () => {
  for (const url of [
    "/",
    "/item/abc",
    "/gallery",
    "/maps",
    "/map/some-map",
    "/games",
    "/games/bar",
    "/games/bar/units",
    "/games/bar/units/armcom",
    "/games/bar/units/armcom/v/1.2",
    "/profile/abc",
    "/developers",
    "/accounts",
    "/api/v1/items",
    "/api/v1/items/abc",
    "/i/abc",
    "/assets/staged/ab/cdef",
    "/assets/games/bar/logo/0123abcd",
    "/export",
    "/feed.xml",
    "/sitemap.xml",
    "/robots.txt",
    "/opengraph-image",
    "/_next/static/chunk.js",
  ]) {
    expect(runsOn(url)).toBe(false);
  }
});

function named(...names: string[]): { name: string }[] {
  return names.map((name) => ({ name }));
}

test("a Supabase session cookie, whole or chunked, counts as signed in", () => {
  expect(hasAuthCookie(named("sb-abcd-auth-token"))).toBe(true);
  expect(hasAuthCookie(named("sb-abcd-auth-token.0", "sb-abcd-auth-token.1"))).toBe(true);
});

test("no cookies, or only unrelated ones, counts as signed out", () => {
  expect(hasAuthCookie(named())).toBe(false);
  expect(hasAuthCookie(named("theme"))).toBe(false);
  expect(hasAuthCookie(named("sb-abcd-auth-token-code-verifier"))).toBe(false);
});

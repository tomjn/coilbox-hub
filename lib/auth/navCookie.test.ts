import { expect, test } from "bun:test";
import {
  encodeNav,
  hasAuthCookie,
  NAV_COOKIE,
  navFromCookies,
  parseCookieHeader,
  parseNav,
  SESSION_MARK_SCRIPT,
  type NavState,
} from "./navCookie";

const ada: NavState = { id: "abc", name: "Ada; Lovelace=1", moderation: "verify" };

/** What the browser holds once Next has set the cookie. */
const asSet = (state: NavState) => encodeURIComponent(encodeNav(state));

test("a written cookie reads back as what was written, separators in the name included", () => {
  expect(parseNav(asSet(ada))).toEqual(ada);
});

test("a name that could not be read survives as null", () => {
  expect(parseNav(asSet({ ...ada, name: null }))).toEqual({ ...ada, name: null });
});

test("anything that is not a NavState reads as nothing", () => {
  for (const raw of [undefined, "", "%", "not json", encodeURIComponent("[]"), encodeURIComponent("null")]) {
    expect(parseNav(raw)).toBeNull();
  }
  const broken = [
    { name: "Ada", moderation: "none" },
    { id: "", name: "Ada", moderation: "none" },
    { id: "abc", name: 4, moderation: "none" },
    { id: "abc", name: "Ada", moderation: "admin" },
  ];
  for (const value of broken) {
    expect(parseNav(encodeURIComponent(JSON.stringify(value)))).toBeNull();
  }
});

test("no Supabase session is signed out, whatever hub_nav says", () => {
  expect(navFromCookies("")).toEqual({ state: "signed-out" });
  expect(navFromCookies(`${NAV_COOKIE}=${asSet(ada)}`)).toEqual({ state: "signed-out" });
});

test("a session with no readable hub_nav is missing, so the header asks for one", () => {
  expect(navFromCookies("sb-ref-auth-token=x")).toEqual({ state: "missing" });
  expect(navFromCookies(`sb-ref-auth-token.0=x; ${NAV_COOKIE}=garbage`)).toEqual({ state: "missing" });
});

test("the inline mark agrees with hasAuthCookie", () => {
  const cases = [
    "",
    "theme=dark",
    "sb-ref-auth-token=x",
    "theme=dark; sb-ref-auth-token.1=x",
    "sb-ref-auth-token-code-verifier=x",
    `${NAV_COOKIE}=x`,
  ];
  for (const cookie of cases) {
    const document = { cookie, documentElement: { dataset: {} as Record<string, string> } };
    new Function("document", SESSION_MARK_SCRIPT)(document);
    const names = [...parseCookieHeader(cookie).keys()].map((name) => ({ name }));
    expect(document.documentElement.dataset.session).toBe(hasAuthCookie(names) ? "in" : "out");
  }
});

test("a session with hub_nav is signed in as hub_nav says", () => {
  expect(navFromCookies(`theme=dark; sb-ref-auth-token=x; ${NAV_COOKIE}=${asSet(ada)}`)).toEqual({
    state: "signed-in",
    nav: ada,
  });
});

import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { isShownTo, ShownTo } from "@/components/ShownTo";
import type { NavState } from "@/lib/auth/navCookie";

const OWNER = "owner";
const as = (id: string, moderation: NavState["moderation"] = "none"): NavState => ({ id, name: null, moderation });

test("the owner sees their own controls and nobody else's", () => {
  expect(isShownTo(as(OWNER), { owner: OWNER })).toBe(true);
  expect(isShownTo(as("stranger"), { owner: OWNER })).toBe(false);
  expect(isShownTo(as(OWNER), { owner: null })).toBe(false);
});

test("a moderator sees moderator controls once they have entered their code", () => {
  expect(isShownTo(as("mod", "granted"), { owner: OWNER, moderators: true })).toBe(true);
  for (const access of ["verify", "enrol", "none"] as const) {
    expect(isShownTo(as("mod", access), { owner: OWNER, moderators: true })).toBe(false);
  }
  expect(isShownTo(as("mod", "granted"), { owner: OWNER })).toBe(false);
});

test("anybody signed in sees what is for anybody signed in", () => {
  expect(isShownTo(as("stranger"), { signedIn: true })).toBe(true);
});

test("the static page carries nothing, whoever it is for", () => {
  expect(renderToStaticMarkup(<ShownTo signedIn>secret</ShownTo>)).toBe("");
});

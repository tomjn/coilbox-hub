import { expect, test } from "bun:test";
import { robotsRules } from "./robots";

const ORIGIN = "https://hub.example";

function disallowed(rules: ReturnType<typeof robotsRules>): string[] {
  const rule = Array.isArray(rules.rules) ? rules.rules[0] : rules.rules;
  const list = rule.disallow;
  return Array.isArray(list) ? list : list ? [list] : [];
}

test("production keeps crawlers out of the private and utility routes", () => {
  const out = disallowed(robotsRules(ORIGIN, "production"));
  for (const path of ["/moderation", "/ops", "/account", "/publish", "/auth", "/api", "/dev", "/i/", "/export"]) {
    expect(out).toContain(path);
  }
  expect(out).toContain("/item/*/edit");
  expect(out).toContain("/games/*/edit");
  expect(out).toContain("/games/*/units/*/compare?with=");
});

test("production allows the public pages and points at the sitemap", () => {
  const rules = robotsRules(ORIGIN, "production");
  expect(rules.sitemap).toBe("https://hub.example/sitemap.xml");
  const rule = Array.isArray(rules.rules) ? rules.rules[0] : rules.rules;
  expect(rule.userAgent).toBe("*");
  expect(rule.allow).toBe("/");
  expect(disallowed(rules)).not.toContain("/");
  expect(disallowed(rules)).not.toContain("/developers");
});

test("production turns AI training crawlers away from the whole site", () => {
  const rules = robotsRules(ORIGIN, "production");
  const list = Array.isArray(rules.rules) ? rules.rules : [rules.rules];
  const blocked = list.find((rule) => rule.disallow === "/");
  expect(blocked?.userAgent).toContain("GPTBot");
  expect(blocked?.userAgent).toContain("ClaudeBot");
  expect(blocked?.userAgent).toContain("CCBot");
});

test("a preview disallows everything and offers no sitemap", () => {
  const rules = robotsRules(ORIGIN, "preview");
  expect(disallowed(rules)).toEqual(["/"]);
  expect(rules.sitemap).toBeUndefined();
});

test("local development behaves as production", () => {
  expect(robotsRules(ORIGIN, undefined).sitemap).toBe("https://hub.example/sitemap.xml");
});

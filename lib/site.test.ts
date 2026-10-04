import { afterEach, expect, test } from "bun:test";
import { importOrigin, siteUrl } from "./site";

const NAMES = ["VERCEL_ENV", "VERCEL_URL", "VERCEL_PROJECT_PRODUCTION_URL"] as const;
const saved = Object.fromEntries(NAMES.map((name) => [name, process.env[name]]));

afterEach(() => {
  for (const name of NAMES) {
    if (saved[name] === undefined) delete process.env[name];
    else process.env[name] = saved[name];
  }
});

function setEnv(values: Partial<Record<(typeof NAMES)[number], string>>) {
  for (const name of NAMES) {
    const value = values[name];
    if (value === undefined) delete process.env[name];
    else process.env[name] = value;
  }
}

test("production import links carry the production domain over https", () => {
  setEnv({
    VERCEL_ENV: "production",
    VERCEL_URL: "hub-abc123.vercel.app",
    VERCEL_PROJECT_PRODUCTION_URL: "hub.example",
  });

  expect(importOrigin()).toBe("https://hub.example");
});

test("a preview's import links carry the preview's own deployment URL", () => {
  setEnv({
    VERCEL_ENV: "preview",
    VERCEL_URL: "hub-abc123.vercel.app",
    VERCEL_PROJECT_PRODUCTION_URL: "hub.example",
  });

  expect(importOrigin()).toBe("https://hub-abc123.vercel.app");
  // The picture routes stay on production, as `siteUrl()` always said.
  expect(siteUrl()).toBe("https://hub.example");
});

test("a preview with no deployment URL falls back to the production domain", () => {
  setEnv({ VERCEL_ENV: "preview", VERCEL_PROJECT_PRODUCTION_URL: "hub.example" });

  expect(importOrigin()).toBe("https://hub.example");
});

test("off Vercel the import origin is localhost", () => {
  setEnv({});

  expect(importOrigin()).toBe("http://localhost:3000");
});

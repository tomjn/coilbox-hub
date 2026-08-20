import { afterEach, expect, test } from "bun:test";
import { DEFAULT_ASSET_CDN_BASE, assetCdnBase, staticTierUrl } from "./cdn";

// Typed read-only by Next.js, since only the toolchain is expected to set it.
// Tests are the one place that legitimately needs to vary it.
const env = process.env as Record<string, string | undefined>;
const original = env.NEXT_PUBLIC_ASSET_CDN_BASE;

afterEach(() => {
  // Deleted rather than assigned when it was unset to begin with. Assigning
  // undefined to process.env stores the string "undefined", which is what Node
  // has always done and what bun started doing in 1.4.0, and a variable holding
  // that word is set as far as every reader is concerned.
  if (original === undefined) delete env.NEXT_PUBLIC_ASSET_CDN_BASE;
  else env.NEXT_PUBLIC_ASSET_CDN_BASE = original;
});

test("unset falls back to the assets repo on Pages rather than throwing", () => {
  delete env.NEXT_PUBLIC_ASSET_CDN_BASE;

  expect(assetCdnBase()).toBe(DEFAULT_ASSET_CDN_BASE);
});

test("an empty or whitespace only override counts as unset", () => {
  env.NEXT_PUBLIC_ASSET_CDN_BASE = "";
  expect(assetCdnBase()).toBe(DEFAULT_ASSET_CDN_BASE);

  env.NEXT_PUBLIC_ASSET_CDN_BASE = "   ";
  expect(assetCdnBase()).toBe(DEFAULT_ASSET_CDN_BASE);
});

test("the override replaces the default, so repointing is config and not a migration", () => {
  env.NEXT_PUBLIC_ASSET_CDN_BASE = "https://assets.example.net/";

  expect(assetCdnBase()).toBe("https://assets.example.net/");
  expect(staticTierUrl("units/bar/abc.webp")).toBe("https://assets.example.net/units/bar/abc.webp");
});

test("a base without a trailing slash gains exactly one", () => {
  env.NEXT_PUBLIC_ASSET_CDN_BASE = "https://assets.example.net/coilbox";

  expect(assetCdnBase()).toBe("https://assets.example.net/coilbox/");
});

test("a base with repeated trailing slashes is still one slash", () => {
  env.NEXT_PUBLIC_ASSET_CDN_BASE = "https://assets.example.net/coilbox///";

  expect(assetCdnBase()).toBe("https://assets.example.net/coilbox/");
});

test("joining does not produce a double slash, whichever side carries it", () => {
  env.NEXT_PUBLIC_ASSET_CDN_BASE = "https://assets.example.net/coilbox/";
  expect(staticTierUrl("maps/abc.webp")).toBe("https://assets.example.net/coilbox/maps/abc.webp");
  expect(staticTierUrl("/maps/abc.webp")).toBe("https://assets.example.net/coilbox/maps/abc.webp");

  env.NEXT_PUBLIC_ASSET_CDN_BASE = "https://assets.example.net/coilbox";
  expect(staticTierUrl("maps/abc.webp")).toBe("https://assets.example.net/coilbox/maps/abc.webp");
  expect(staticTierUrl("/maps/abc.webp")).toBe("https://assets.example.net/coilbox/maps/abc.webp");
});

test("joining does not eat the subpath segment the Pages base depends on", () => {
  delete env.NEXT_PUBLIC_ASSET_CDN_BASE;

  // `new URL("/maps/abc.webp", base)` would resolve against the origin and drop
  // `/coilbox-assets`, which is where every file actually lives.
  expect(staticTierUrl("/maps/abc.webp")).toBe(
    "https://tomjn.github.io/coilbox-assets/maps/abc.webp",
  );
  expect(staticTierUrl("maps/abc.webp")).toBe(
    "https://tomjn.github.io/coilbox-assets/maps/abc.webp",
  );
});

test("nested tier relative paths survive intact", () => {
  delete env.NEXT_PUBLIC_ASSET_CDN_BASE;

  expect(staticTierUrl("units/bar/render/270/0a1b2c3d.webp")).toBe(
    "https://tomjn.github.io/coilbox-assets/units/bar/render/270/0a1b2c3d.webp",
  );
});

test("the default base ends in a slash, so the constant and the function agree", () => {
  expect(DEFAULT_ASSET_CDN_BASE.endsWith("/")).toBe(true);
});

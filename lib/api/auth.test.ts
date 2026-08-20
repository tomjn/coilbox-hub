import { afterEach, expect, test } from "bun:test";
import { ASSET_VOCABULARY_DIGEST } from "@/lib/assets/vocabularyDigest";
import { MAP_CATALOG_DIGEST } from "@/lib/maps/catalogDigest";
import { AUTH_FORMAT, AUTH_VERSION, buildAuthBody } from "./auth";

// Both are typed read-only by Next.js, since only the toolchain is expected
// to set them. Tests are the one place that legitimately needs to vary them.
const env = process.env as Record<string, string | undefined>;
const originalSupabaseUrl = env.NEXT_PUBLIC_SUPABASE_URL;
const originalPublishableKey = env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY;

/** Puts a variable back as it was, including back to not being set at all. */
function restore(name: string, value: string | undefined): void {
  if (value === undefined) delete env[name];
  else env[name] = value;
}

afterEach(() => {
  // See lib/assets/cdn.test.ts: assigning undefined stores the word rather than
  // removing the variable.
  restore("NEXT_PUBLIC_SUPABASE_URL", originalSupabaseUrl);
  restore("NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY", originalPublishableKey);
});

test("the body carries the format marker, version and both values", () => {
  env.NEXT_PUBLIC_SUPABASE_URL = "http://127.0.0.1:54321";
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = "sb_publishable_example";

  const result = buildAuthBody();

  expect(result.ok).toBe(true);
  if (result.ok) {
    expect(result.body.format).toBe(AUTH_FORMAT);
    expect(result.body.version).toBe(AUTH_VERSION);
    expect(result.body.version).toBe(1);
    expect(result.body.supabase_url).toBe("http://127.0.0.1:54321");
    expect(result.body.publishable_key).toBe("sb_publishable_example");
  }
});

/**
 * What a client compares against its own copy to find out it is behind (#165).
 * Vendoring keeps the two files byte identical, so the digest of the bytes is
 * the whole comparison and needs no agreement about formatting.
 */
test("the body carries the asset vocabulary digest, without a version bump", () => {
  env.NEXT_PUBLIC_SUPABASE_URL = "http://127.0.0.1:54321";
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = "sb_publishable_example";

  const result = buildAuthBody();

  expect(result.ok).toBe(true);
  if (result.ok) {
    expect(result.body.asset_vocabulary).toBe(ASSET_VOCABULARY_DIGEST);
    expect(result.body.asset_vocabulary).toMatch(/^sha256:[0-9a-f]{64}$/);
    // Adding a field is additive and an old client ignores it. The version is
    // for a shape that changed under somebody, and coilbox refuses a document
    // whose version it does not know.
    expect(result.body.version).toBe(1);
  }
});

/**
 * The catalog digest, for the same reason and on the same document (#185). Both
 * are here, and they are different values, because a client acts differently on
 * each: one mismatch means it cannot encode a picture correctly, the other that
 * it cannot describe a map correctly.
 */
test("the body carries the map catalog digest, without a version bump", () => {
  env.NEXT_PUBLIC_SUPABASE_URL = "http://127.0.0.1:54321";
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = "sb_publishable_example";

  const result = buildAuthBody();

  expect(result.ok).toBe(true);
  if (result.ok) {
    expect(result.body.map_catalog).toBe(MAP_CATALOG_DIGEST);
    expect(result.body.map_catalog).toMatch(/^sha256:[0-9a-f]{64}$/);
    expect(result.body.map_catalog).not.toBe(result.body.asset_vocabulary);
    expect(result.body.version).toBe(1);
  }
});

test("a missing Supabase URL is not ok, rather than a body with an undefined field", () => {
  delete env.NEXT_PUBLIC_SUPABASE_URL;
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = "sb_publishable_example";

  const result = buildAuthBody();

  expect(result.ok).toBe(false);
});

test("a missing publishable key is not ok, rather than a body with an undefined field", () => {
  env.NEXT_PUBLIC_SUPABASE_URL = "http://127.0.0.1:54321";
  delete env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY;

  const result = buildAuthBody();

  expect(result.ok).toBe(false);
});

test("both missing is not ok either", () => {
  delete env.NEXT_PUBLIC_SUPABASE_URL;
  delete env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY;

  const result = buildAuthBody();

  expect(result.ok).toBe(false);
});

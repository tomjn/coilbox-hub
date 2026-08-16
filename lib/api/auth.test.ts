import { afterEach, expect, test } from "bun:test";
import { ASSET_VOCABULARY_DIGEST } from "@/lib/assets/vocabularyDigest";
import { AUTH_FORMAT, AUTH_VERSION, buildAuthBody } from "./auth";

// Both are typed read-only by Next.js, since only the toolchain is expected
// to set them. Tests are the one place that legitimately needs to vary them.
const env = process.env as Record<string, string | undefined>;
const originalSupabaseUrl = env.NEXT_PUBLIC_SUPABASE_URL;
const originalPublishableKey = env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY;

afterEach(() => {
  env.NEXT_PUBLIC_SUPABASE_URL = originalSupabaseUrl;
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = originalPublishableKey;
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

test("a missing Supabase URL is not ok, rather than a body with an undefined field", () => {
  env.NEXT_PUBLIC_SUPABASE_URL = undefined;
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = "sb_publishable_example";

  const result = buildAuthBody();

  expect(result.ok).toBe(false);
});

test("a missing publishable key is not ok, rather than a body with an undefined field", () => {
  env.NEXT_PUBLIC_SUPABASE_URL = "http://127.0.0.1:54321";
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = undefined;

  const result = buildAuthBody();

  expect(result.ok).toBe(false);
});

test("both missing is not ok either", () => {
  env.NEXT_PUBLIC_SUPABASE_URL = undefined;
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = undefined;

  const result = buildAuthBody();

  expect(result.ok).toBe(false);
});

import { afterEach, expect, test } from "bun:test";
import {
  getSupabaseConfig,
  requireSupabaseConfig,
  requireSupabaseServiceRoleKey,
} from "./config";

// Both are typed read-only by Next.js, since only the toolchain is expected
// to set them. Tests are the one place that legitimately needs to vary them.
const env = process.env as Record<string, string | undefined>;
const originalSupabaseUrl = env.NEXT_PUBLIC_SUPABASE_URL;
const originalPublishableKey = env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY;
const originalServiceRoleKey = env.SUPABASE_SERVICE_ROLE_KEY;

afterEach(() => {
  env.NEXT_PUBLIC_SUPABASE_URL = originalSupabaseUrl;
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = originalPublishableKey;
  env.SUPABASE_SERVICE_ROLE_KEY = originalServiceRoleKey;
});

test("both values present is ok, and carries them through unchanged", () => {
  env.NEXT_PUBLIC_SUPABASE_URL = "http://127.0.0.1:54321";
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = "sb_publishable_example";

  const result = getSupabaseConfig();

  expect(result.ok).toBe(true);
  if (result.ok) {
    expect(result.config.url).toBe("http://127.0.0.1:54321");
    expect(result.config.publishableKey).toBe("sb_publishable_example");
  }
});

test("a missing Supabase URL is not ok", () => {
  env.NEXT_PUBLIC_SUPABASE_URL = undefined;
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = "sb_publishable_example";

  expect(getSupabaseConfig().ok).toBe(false);
});

test("a missing publishable key is not ok", () => {
  env.NEXT_PUBLIC_SUPABASE_URL = "http://127.0.0.1:54321";
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = undefined;

  expect(getSupabaseConfig().ok).toBe(false);
});

test("both missing is not ok either", () => {
  env.NEXT_PUBLIC_SUPABASE_URL = undefined;
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = undefined;

  expect(getSupabaseConfig().ok).toBe(false);
});

test("an empty string counts as missing, not as a value", () => {
  env.NEXT_PUBLIC_SUPABASE_URL = "";
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = "sb_publishable_example";

  expect(getSupabaseConfig().ok).toBe(false);
});

test("requireSupabaseConfig returns the config when it is present", () => {
  env.NEXT_PUBLIC_SUPABASE_URL = "http://127.0.0.1:54321";
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = "sb_publishable_example";

  expect(requireSupabaseConfig()).toEqual({
    url: "http://127.0.0.1:54321",
    publishableKey: "sb_publishable_example",
  });
});

test("requireSupabaseConfig throws a descriptive error when config is missing", () => {
  env.NEXT_PUBLIC_SUPABASE_URL = undefined;
  env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY = undefined;

  expect(() => requireSupabaseConfig()).toThrow(/Supabase/);
});

test("requireSupabaseServiceRoleKey returns the key when it is present", () => {
  env.SUPABASE_SERVICE_ROLE_KEY = "service-role-example";

  expect(requireSupabaseServiceRoleKey()).toBe("service-role-example");
});

test("requireSupabaseServiceRoleKey throws a descriptive error when it is missing", () => {
  env.SUPABASE_SERVICE_ROLE_KEY = undefined;

  expect(() => requireSupabaseServiceRoleKey()).toThrow(/Supabase/);
});

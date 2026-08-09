import { afterEach, expect, test } from "bun:test";
import { isDevSignInEnabled, isLoopbackUrl } from "./loopback";

test("the local Supabase URL is loopback", () => {
  expect(isLoopbackUrl("http://127.0.0.1:54321")).toBe(true);
});

test("localhost and the IPv6 loopback address are loopback too", () => {
  expect(isLoopbackUrl("http://localhost:54321")).toBe(true);
  expect(isLoopbackUrl("http://[::1]:54321")).toBe(true);
});

test("a hosted Supabase project is not loopback", () => {
  expect(isLoopbackUrl("https://abcdefgh.supabase.co")).toBe(false);
});

test("an obviously fake host is not loopback", () => {
  expect(isLoopbackUrl("https://not-real.example.test")).toBe(false);
});

test("a malformed URL is not loopback", () => {
  expect(isLoopbackUrl("not a url")).toBe(false);
});

// NODE_ENV is typed read-only, since Next.js only expects it to be set by
// the toolchain. Tests are the one place that legitimately needs to vary it.
const env = process.env as Record<string, string | undefined>;
const originalNodeEnv = env.NODE_ENV;
const originalSupabaseUrl = env.NEXT_PUBLIC_SUPABASE_URL;

afterEach(() => {
  env.NODE_ENV = originalNodeEnv;
  env.NEXT_PUBLIC_SUPABASE_URL = originalSupabaseUrl;
});

test("the dev sign in is enabled in development against a loopback Supabase URL", () => {
  env.NODE_ENV = "development";
  env.NEXT_PUBLIC_SUPABASE_URL = "http://127.0.0.1:54321";
  expect(isDevSignInEnabled()).toBe(true);
});

test("the dev sign in is disabled outside development", () => {
  env.NODE_ENV = "production";
  env.NEXT_PUBLIC_SUPABASE_URL = "http://127.0.0.1:54321";
  expect(isDevSignInEnabled()).toBe(false);
});

test("the dev sign in is disabled when the Supabase URL is not loopback", () => {
  env.NODE_ENV = "development";
  env.NEXT_PUBLIC_SUPABASE_URL = "https://not-real.example.test";
  expect(isDevSignInEnabled()).toBe(false);
});

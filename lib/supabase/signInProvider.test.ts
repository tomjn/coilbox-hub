import { expect, test } from "bun:test";
import { signInProvider } from "@/lib/supabase/signInProvider";

test("accepts each provider on the list", () => {
  expect(signInProvider("discord")).toBe("discord");
  expect(signInProvider("github")).toBe("github");
  expect(signInProvider("twitch")).toBe("twitch");
});

test("refuses a provider Supabase knows but the hub does not offer", () => {
  expect(signInProvider("google")).toBeNull();
});

test("refuses a missing, empty or differently cased value", () => {
  expect(signInProvider(null)).toBeNull();
  expect(signInProvider(undefined)).toBeNull();
  expect(signInProvider("")).toBeNull();
  expect(signInProvider("GitHub")).toBeNull();
});

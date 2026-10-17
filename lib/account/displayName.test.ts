import { expect, test } from "bun:test";
import {
  DISPLAY_NAME_MAX,
  DISPLAY_NAME_MIN,
  checkDisplayName,
  renameErrorMessage,
} from "./displayName";

/**
 * The bounds come from the check constraint on public.profile (issue #429):
 * length(btrim(display_name)) between 2 and 40. The hub states them before the
 * write so the person gets a sentence, not a constraint violation (#434).
 */

test("the bounds are the ones the profile table enforces", () => {
  expect(DISPLAY_NAME_MIN).toBe(2);
  expect(DISPLAY_NAME_MAX).toBe(40);
});

test("a name inside the bounds is returned as it is", () => {
  expect(checkDisplayName("Ada")).toEqual({ name: "Ada" });
});

test("leading and trailing whitespace is trimmed, not refused", () => {
  expect(checkDisplayName("  Ada \n")).toEqual({ name: "Ada" });
});

test("repeated whitespace inside the name is collapsed to one space", () => {
  expect(checkDisplayName("Ada   \t Lovelace")).toEqual({ name: "Ada Lovelace" });
});

test("the bounds are measured after trimming", () => {
  expect(checkDisplayName(" A ")).toHaveProperty("error");
  expect(checkDisplayName(`  ${"x".repeat(40)}  `)).toEqual({ name: "x".repeat(40) });
});

test("two characters is the shortest, one is too short", () => {
  expect(checkDisplayName("Ab")).toEqual({ name: "Ab" });
  const short = checkDisplayName("A");
  expect(short).toHaveProperty("error");
  expect((short as { error: string }).error).toContain("at least 2");
});

test("forty characters is the longest, forty one is too long", () => {
  expect(checkDisplayName("x".repeat(40))).toEqual({ name: "x".repeat(40) });
  const long = checkDisplayName("x".repeat(41));
  expect(long).toHaveProperty("error");
  expect((long as { error: string }).error).toContain("at most 40");
});

test("characters outside the basic plane count once, as the database counts them", () => {
  expect(checkDisplayName("\u{1F600}".repeat(40))).toHaveProperty("name");
  expect(checkDisplayName("\u{1F600}".repeat(41))).toHaveProperty("error");
});

test("an empty or missing field is too short", () => {
  expect(checkDisplayName("")).toHaveProperty("error");
  expect(checkDisplayName("    ")).toHaveProperty("error");
  expect(checkDisplayName(null)).toHaveProperty("error");
  expect(checkDisplayName(new File([], "Ada"))).toHaveProperty("error");
});

test("a unique violation says the name is taken", () => {
  expect(renameErrorMessage("23505")).toContain("already in use");
});

test("a check violation repeats the bounds", () => {
  expect(renameErrorMessage("23514")).toContain("between 2 and 40");
});

test("any other failure asks the person to try again", () => {
  expect(renameErrorMessage("XX000")).toContain("Try again");
  expect(renameErrorMessage(undefined)).toContain("Try again");
});

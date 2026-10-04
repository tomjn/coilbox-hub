import { expect, test } from "bun:test";
import { confirmsDeletion } from "./confirmDeletion";

/**
 * The typed name has to match the account name before the delete action runs (#447).
 * Surrounding whitespace is ignored and case is not.
 */

test("the exact name matches", () => {
  expect(confirmsDeletion("Ada", "Ada")).toBe(true);
});

test("a different name does not match", () => {
  expect(confirmsDeletion("Grace", "Ada")).toBe(false);
});

test("surrounding whitespace is ignored on both sides", () => {
  expect(confirmsDeletion("  Ada \n", "Ada")).toBe(true);
  expect(confirmsDeletion("Ada", " Ada ")).toBe(true);
});

test("whitespace inside the name still counts", () => {
  expect(confirmsDeletion("Ada  Lovelace", "Ada Lovelace")).toBe(false);
});

test("case has to match", () => {
  expect(confirmsDeletion("ada", "Ada")).toBe(false);
});

test("a missing field never matches", () => {
  expect(confirmsDeletion(null, "Ada")).toBe(false);
  expect(confirmsDeletion(undefined, "Ada")).toBe(false);
  expect(confirmsDeletion("", "Ada")).toBe(false);
});

test("an empty account name is never matched by an empty field", () => {
  expect(confirmsDeletion("", "")).toBe(false);
  expect(confirmsDeletion("   ", "")).toBe(false);
});

test("a file upload in the field does not match", () => {
  expect(confirmsDeletion(new File([], "Ada"), "Ada")).toBe(false);
});

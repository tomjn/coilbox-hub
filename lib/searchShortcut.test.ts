import { expect, test } from "bun:test";
import { type ShortcutEvent, shouldFocusSearch } from "./searchShortcut";

const slash: ShortcutEvent = {
  key: "/",
  ctrlKey: false,
  metaKey: false,
  altKey: false,
  isComposing: false,
  defaultPrevented: false,
  targetTag: "BODY",
  targetEditable: false,
};

test("a plain slash focuses the search box", () => {
  expect(shouldFocusSearch(slash)).toBe(true);
});

test("a slash typed with shift still counts", () => {
  expect(shouldFocusSearch({ ...slash, shiftKey: true } as ShortcutEvent)).toBe(
    true,
  );
});

test("a slash with ctrl, meta or alt held is left alone", () => {
  expect(shouldFocusSearch({ ...slash, ctrlKey: true })).toBe(false);
  expect(shouldFocusSearch({ ...slash, metaKey: true })).toBe(false);
  expect(shouldFocusSearch({ ...slash, altKey: true })).toBe(false);
});

test("a slash during composition or already handled is left alone", () => {
  expect(shouldFocusSearch({ ...slash, isComposing: true })).toBe(false);
  expect(shouldFocusSearch({ ...slash, defaultPrevented: true })).toBe(false);
});

test("a slash inside a form field is left alone", () => {
  for (const targetTag of ["INPUT", "TEXTAREA", "SELECT"]) {
    expect(shouldFocusSearch({ ...slash, targetTag })).toBe(false);
  }
});

test("a slash inside an editable element is left alone", () => {
  expect(shouldFocusSearch({ ...slash, targetTag: "DIV", targetEditable: true })).toBe(
    false,
  );
});

test("a slash on a link still counts", () => {
  expect(shouldFocusSearch({ ...slash, targetTag: "A" })).toBe(true);
});

test("another key does nothing", () => {
  expect(shouldFocusSearch({ ...slash, key: "a" })).toBe(false);
});

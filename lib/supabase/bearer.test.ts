import { expect, test } from "bun:test";
import { extractBearerToken } from "./bearer";

function requestWithAuth(header: string | undefined) {
  const headers = new Headers();
  if (header !== undefined) headers.set("authorization", header);
  return new Request("http://localhost/api/v1/items", { method: "POST", headers });
}

test("no Authorization header at all yields no token", () => {
  expect(extractBearerToken(requestWithAuth(undefined))).toBeNull();
});

test("a well formed bearer header yields the token", () => {
  expect(extractBearerToken(requestWithAuth("Bearer abc.def.ghi"))).toBe("abc.def.ghi");
});

test("the scheme is matched case-insensitively", () => {
  expect(extractBearerToken(requestWithAuth("bearer abc.def.ghi"))).toBe("abc.def.ghi");
});

test("a different scheme yields no token", () => {
  expect(extractBearerToken(requestWithAuth("Basic dXNlcjpwYXNz"))).toBeNull();
});

test("Bearer with nothing after it yields no token", () => {
  expect(extractBearerToken(requestWithAuth("Bearer"))).toBeNull();
});

test("Bearer followed only by whitespace yields no token", () => {
  expect(extractBearerToken(requestWithAuth("Bearer    "))).toBeNull();
});

test("surrounding whitespace around the token is trimmed", () => {
  expect(extractBearerToken(requestWithAuth("  Bearer   abc.def.ghi  "))).toBe("abc.def.ghi");
});

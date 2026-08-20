import { expect, test } from "bun:test";
import { userFromClaims } from "@/lib/supabase/user";

test("reads the id and the display metadata off a token's claims", () => {
  expect(userFromClaims({ sub: "abc", user_metadata: { full_name: "Ada" } })).toEqual({
    id: "abc",
    metadata: { full_name: "Ada" },
  });
});

test("a token without metadata still names its user", () => {
  expect(userFromClaims({ sub: "abc" })).toEqual({ id: "abc", metadata: {} });
});

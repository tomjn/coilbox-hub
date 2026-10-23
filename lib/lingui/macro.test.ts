import { expect, test } from "bun:test";
import { t } from "@lingui/core/macro";
import { planetOf } from "@/lib/conquest/planets";

test("returns the English text", () => {
  expect(t`No change`).toBe("No change");
});

test("fills in each value where it was written", () => {
  const salvage = 12;
  const hullCost = 3;
  expect(t`+${salvage} salvage, -${hullCost} hull`).toBe("+12 salvage, -3 hull");
});

test("keeps escapes as the characters they stand for", () => {
  expect(t`a\tb`).toBe("a\tb");
});

/** The import resolves through tsconfig paths, so a vendored file reads English labels. */
test("a vendored planet reads its label through the alias", () => {
  expect(planetOf("temperate").label).toBe("Temperate");
});

import { expect, test } from "bun:test";
import {
  conquest,
  downloads,
  scenario,
  setupPacks,
  skirmish,
  warpath,
} from "@/components/art/drawings";
import { itemArt } from "./itemArt";

test("each gallery kind gets its own drawing", () => {
  expect(itemArt("preset").drawing).toBe(skirmish);
  expect(itemArt("scenario").drawing).toBe(scenario);
  expect(itemArt("setup-pack").drawing).toBe(setupPacks);
});

test("a challenge picks its drawing from mode, not kind", () => {
  expect(itemArt("challenge", "conquest").drawing).toBe(conquest);
  expect(itemArt("challenge", "warpath").drawing).toBe(warpath);
});

test("a challenge with no recognised mode falls back to downloads", () => {
  expect(itemArt("challenge", null).drawing).toBe(downloads);
  expect(itemArt("challenge", undefined).drawing).toBe(downloads);
  expect(itemArt("challenge", "some-future-mode").drawing).toBe(downloads);
});

test("a kind the gallery does not carry, such as a campaign, falls back to downloads", () => {
  expect(itemArt("campaign").drawing).toBe(downloads);
  expect(itemArt("something-a-newer-coilbox-invented").drawing).toBe(downloads);
});

test("every drawing this maps to has a tuned strength, not the fallback by accident", () => {
  for (const kind of ["preset", "scenario", "setup-pack"] as const) {
    const { drawing, strength } = itemArt(kind);
    expect(strength).toBeGreaterThan(0);
    expect(drawing.id).not.toBe(downloads.id);
  }
  for (const mode of ["conquest", "warpath"] as const) {
    const { drawing, strength } = itemArt("challenge", mode);
    expect(strength).toBeGreaterThan(0);
    expect(drawing.id).not.toBe(downloads.id);
  }
});

import { expect, test } from "bun:test";
import { GALLERY_KINDS } from "@/lib/container";
import { kindLabelPlural, kindsPlural, kindsSingular } from "./label";

/**
 * The sentence the site uses to say what it carries (tomjn/coilbox#1502).
 * Assembled from `GALLERY_KINDS` rather than written out, because the hand
 * written version said four kinds while the filter chips under it offered
 * five.
 */

test("the kinds read as a sentence, in the plural", () => {
  expect(kindsPlural()).toBe(
    "Presets, challenges, setup packs, scenarios and blueprints",
  );
});

test("the kinds read as a sentence in the singular, for sharing one thing", () => {
  expect(kindsSingular()).toBe(
    "preset, challenge, setup pack, scenario or blueprint",
  );
});

test("neither sentence can fall behind the kinds the gallery carries", () => {
  for (const kind of GALLERY_KINDS) {
    expect(kindsPlural().toLowerCase()).toContain(
      kindLabelPlural(kind).toLowerCase(),
    );
  }
});

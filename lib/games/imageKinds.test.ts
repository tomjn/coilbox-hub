import { expect, test } from "bun:test";
import { GAME_IMAGE_KINDS, isGameImageKind, parseGameBrandingFields } from "@/lib/api/gameBranding";
import { IMAGE_TARGETS, targetFormat } from "./imageResize";
import { sendGameImage } from "./imageUpload";

/**
 * The kinds of picture a game carries, as one list rather than a pair repeated
 * across six files (#345 gave two, this adds the third).
 *
 * The last test here is the one that matters most. Adding a kind to a
 * two-branch ternary does not fail to compile, it just quietly picks the wrong
 * branch, so the guard has to be a test rather than a type.
 */

test("the three kinds are listed in one place", () => {
  expect(GAME_IMAGE_KINDS).toEqual(["logo", "banner", "card"]);
});

test("every kind has a box and a format", () => {
  for (const kind of GAME_IMAGE_KINDS) {
    expect(IMAGE_TARGETS[kind].maxWidth).toBeGreaterThan(0);
    expect(targetFormat(kind)).toMatch(/^image\/(png|webp)$/);
  }
});

test("only the logo is forced to PNG, because only it reaches the preview renderer", () => {
  expect(targetFormat("logo")).toBe("image/png");
  expect(targetFormat("banner")).toBe("image/webp");
  expect(targetFormat("card")).toBe("image/webp");
});

test("card art is 16:9", () => {
  const { maxWidth, maxHeight } = IMAGE_TARGETS.card;
  expect(maxWidth / maxHeight).toBeCloseTo(16 / 9, 5);
});

test("isGameImageKind refuses anything not in the list", () => {
  expect(isGameImageKind("card")).toBe(true);
  expect(isGameImageKind("screenshot")).toBe(false);
  expect(isGameImageKind("")).toBe(false);
});

test("the branding route accepts a card", () => {
  const form = new FormData();
  form.set("shortname", "BA");
  form.set("kind", "card");
  expect(parseGameBrandingFields(form)).toEqual({ ok: true, shortname: "BA", kind: "card" });
});

test("the branding route still refuses a kind the hub does not hold", () => {
  const form = new FormData();
  form.set("shortname", "BA");
  form.set("kind", "screenshot");
  expect(parseGameBrandingFields(form).ok).toBe(false);
});

test("a card upload is not sent as a banner", async () => {
  // What this guards: `sendGameImage` read the kind with a two-way ternary, so
  // anything that was not "logo" became "banner". A card would have been shrunk
  // to the banner's 2048x448 box and stored under the banner's name.
  const form = new FormData();
  form.set("kind", "card");
  form.set("image", new File([new Uint8Array(8)], "card.png", { type: "image/png" }));

  // An array rather than a `let`, because TypeScript narrows a variable only
  // ever assigned inside a callback and then rejects the comparison.
  const seen: string[] = [];
  await sendGameImage(
    async (_previous, sent) => {
      seen.push(String(sent.get("kind")));
      return { ok: true, message: "stored" };
    },
    null,
    form,
    async (file) => ({ ok: true, file, message: "converted" }),
  );

  expect(seen).toEqual(["card"]);
});

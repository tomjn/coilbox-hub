import { expect, test } from "bun:test";
import { previewAsJpeg, previewAtSize } from "./previewUrl";

/** A real URL from BAR's list. */
const url =
  "https://maps-metadata.beyondallreason.dev/i/fit-in/1024x1024/filters:format(webp):quality(75)/rowy-1f075.appspot.com/maps/zzz/photo/AcidicQuarry_5.16.jpg";

test("resizing keeps everything but the fitted size", () => {
  expect(previewAtSize(url, 512)).toBe(
    "https://maps-metadata.beyondallreason.dev/i/fit-in/512x512/filters:format(webp):quality(75)/rowy-1f075.appspot.com/maps/zzz/photo/AcidicQuarry_5.16.jpg",
  );
});

test("asking for jpeg swaps the format and leaves the rest alone", () => {
  expect(previewAsJpeg(url, 600)).toBe(
    "https://maps-metadata.beyondallreason.dev/i/fit-in/600x600/filters:format(jpeg):quality(75)/rowy-1f075.appspot.com/maps/zzz/photo/AcidicQuarry_5.16.jpg",
  );
});

test("a filter list with no format gains one", () => {
  const plain = url.replace("format(webp):", "");
  expect(previewAsJpeg(plain, 600)).toContain("filters:quality(75):format(jpeg)");
});

test("a URL of another shape is left alone rather than mangled", () => {
  const other = "https://example.com/maps/preview.png";
  expect(previewAtSize(other, 512)).toBe(other);
});

test("a URL that cannot be rewritten is refused for jpeg, not guessed at", () => {
  expect(previewAsJpeg("https://example.com/maps/preview.webp", 600)).toBeNull();
});

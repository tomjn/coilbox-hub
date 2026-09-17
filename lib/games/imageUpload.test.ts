import { expect, mock, test } from "bun:test";
import { GAME_BRANDING_MAX_BYTES, type GameImageKind } from "@/lib/api/gameBranding";
import type { ConvertResult } from "@/lib/games/imageResize";
import { refuseImageFile, sendGameImage, UPLOAD_MESSAGES } from "./imageUpload";

/** 2x2, 8 bit RGB - the same frozen encoder output `lib/assets/imageHeader.test.ts`
 *  and `app/games/editing.test.ts` use, so a real PNG header is on hand without
 *  a browser to make one. */
const PNG = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAAEklEQVQImWMQqTghUnGCAUIBACJuBVHwJsa1AAAAAElFTkSuQmCC",
  "base64",
);

/** 3x2, lossy WebP - the same frozen libwebp output `lib/assets/imageHeader.test.ts`
 *  uses as `lossyWebp`. Small enough to pass every other check `planImageUpload`
 *  makes, so it isolates the #366 rule that a logo converts regardless. */
const WEBP = Buffer.from("UklGRjoAAABXRUJQVlA4IC4AAAAQAgCdASoDAAIAAUAmJaACdLoB+AH4AAPIAP7udn/+oLQ18vxov/U4MHPn4/wA", "base64");

function form(file?: File, kind: GameImageKind = "banner"): FormData {
  const data = new FormData();
  data.set("shortname", "BA");
  data.set("kind", kind);
  if (file) data.set("image", file);
  return data;
}

function bytes(count: number): File {
  return new File([new Uint8Array(count)], "banner.png", { type: "image/png" });
}

function pngFile(name = "banner.png"): File {
  return new File([PNG], name, { type: "image/png" });
}

/** `sendGameImage` never calls this in these tests unless a test says it
 *  should - a real conversion needs a browser, which is covered by hand
 *  (#356, #359), not here. */
function unusedConvert(): Promise<ConvertResult> {
  throw new Error("convert should not have been called");
}

test("no file and an empty file both ask for a file", () => {
  expect(refuseImageFile(null)).toEqual({ ok: false, message: UPLOAD_MESSAGES.noFile });
  expect(refuseImageFile(bytes(0))).toEqual({ ok: false, message: UPLOAD_MESSAGES.noFile });
});

test("a file over the limit is refused with its size and the limit", () => {
  expect(refuseImageFile(bytes(720_583))).toEqual({
    ok: false,
    message: "This file is 704 KB. The largest picture you can upload is 512 KB. Make it smaller and try again.",
  });
});

test("a file of exactly the limit is not refused", () => {
  expect(refuseImageFile(bytes(GAME_BRANDING_MAX_BYTES))).toBeNull();
  expect(refuseImageFile(bytes(GAME_BRANDING_MAX_BYTES + 1))?.ok).toBe(false);
});

test("sendGameImage asks for a file without reading anything when none was chosen", async () => {
  const send = mock(async () => ({ ok: true, message: "sent" }));

  const state = await sendGameImage(send, null, form(), unusedConvert);

  expect(state).toEqual({ ok: false, message: UPLOAD_MESSAGES.noFile });
  expect(send).not.toHaveBeenCalled();
});

test("a PNG already inside its box and under the byte limit is sent unchanged, without converting", async () => {
  const send = mock(async () => ({ ok: true, message: "Banner uploaded." }));

  const state = await sendGameImage(send, null, form(pngFile()), unusedConvert);

  expect(state).toEqual({ ok: true, message: "Banner uploaded." });
  expect(send).toHaveBeenCalledTimes(1);
});

test("a WebP banner already inside its box and under the byte limit is sent unchanged, without converting", async () => {
  const send = mock(async () => ({ ok: true, message: "Banner uploaded." }));
  const webpFile = new File([WEBP], "banner.webp", { type: "image/webp" });

  const state = await sendGameImage(send, null, form(webpFile), unusedConvert);

  expect(state).toEqual({ ok: true, message: "Banner uploaded." });
  expect(send).toHaveBeenCalledTimes(1);
});

test("a WebP logo is always handed to convert, even inside its box and under the byte limit (#366)", async () => {
  const send = mock(async () => ({ ok: true, message: "Logo uploaded." }));
  const converted = pngFile("logo.png");
  // Both parameters are unused in the body: they are here so bun records
  // `convert`'s call arguments with the real two-argument shape, which the
  // assertion on `convert.mock.calls[0]` below reads.
  const convert = mock(
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    async (_file: File, _kind: GameImageKind): Promise<ConvertResult> => ({
      ok: true,
      file: converted,
      message: "Converted from WebP (1 KB) to PNG (1 KB).",
    }),
  );
  const webpFile = new File([WEBP], "logo.webp", { type: "image/webp" });

  const state = await sendGameImage(send, null, form(webpFile, "logo"), convert);

  expect(convert).toHaveBeenCalledTimes(1);
  expect(convert.mock.calls[0]?.[1]).toBe("logo");
  expect(state).toEqual({ ok: true, message: "Converted from WebP (1 KB) to PNG (1 KB). Logo uploaded." });
});

test("bytes that are not a readable PNG or WebP header are handed to convert", async () => {
  const send = mock(async () => ({ ok: true, message: "Banner uploaded." }));
  const jpeg = new Uint8Array(64);
  jpeg.set([0xff, 0xd8, 0xff, 0xe0]);
  // Both parameters are unused in the body: they are here so bun records
  // `convert`'s call arguments with the real two-argument shape, which the
  // assertion on `convert.mock.calls[0]` below reads.
  const convert = mock(
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    async (_file: File, _kind: GameImageKind): Promise<ConvertResult> => ({
      ok: true,
      file: pngFile("banner.webp"),
      message: "Converted from JPEG (1 KB) to WebP (1 KB).",
    }),
  );

  const state = await sendGameImage(send, null, form(new File([jpeg], "photo.jpg", { type: "image/jpeg" })), convert);

  expect(convert).toHaveBeenCalledTimes(1);
  expect(convert.mock.calls[0]?.[1]).toBe("banner");
  expect(state).toEqual({ ok: true, message: "Converted from JPEG (1 KB) to WebP (1 KB). Banner uploaded." });
});

test("a real PNG over the byte limit is handed to convert, and its file replaces the one sent", async () => {
  // Both parameters are unused in the body: they are here so bun records
  // `send`'s call arguments with the real two-argument shape, which the
  // assertion on `send.mock.calls[0]` below reads to find the sent FormData.
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const send = mock(async (_previous: unknown, _sentForm: FormData) => ({ ok: true, message: "Logo uploaded." }));
  const converted = pngFile("logo.webp");
  const convert = mock(async (): Promise<ConvertResult> => ({ ok: true, file: converted, message: "Shrunk from 704 KB to 12 KB." }));

  // A real PNG header (so `planImageUpload` reads real dimensions, not a null
  // header), padded well past `GAME_BRANDING_MAX_BYTES` - the header parser
  // only reads the first 26 bytes, so padding after it is harmless.
  const big = new File([PNG, new Uint8Array(720_583 - PNG.length)], "logo.png", { type: "image/png" });
  const state = await sendGameImage(send, null, form(big, "logo"), convert);

  expect(state).toEqual({ ok: true, message: "Shrunk from 704 KB to 12 KB. Logo uploaded." });
  const sentForm = send.mock.calls[0]?.[1];
  const sentFile = sentForm?.get("image");
  expect(sentFile).toBeInstanceOf(File);
  expect((sentFile as File).name).toBe("logo.webp");
  expect((sentFile as File).size).toBe(converted.size);
});

test("convert giving up is shown as a refusal, and the server is never called", async () => {
  const send = mock(async () => ({ ok: true, message: "sent" }));
  const jpeg = new Uint8Array(64);
  jpeg.set([0xff, 0xd8, 0xff, 0xe0]);
  const convert = mock(
    async (): Promise<ConvertResult> => ({
      ok: false,
      message: "This picture is still 900 KB after shrinking it as far as the hub allows. Try a smaller or simpler picture.",
    }),
  );

  const state = await sendGameImage(send, null, form(new File([jpeg], "photo.jpg", { type: "image/jpeg" })), convert);

  expect(state).toEqual({
    ok: false,
    message: "This picture is still 900 KB after shrinking it as far as the hub allows. Try a smaller or simpler picture.",
  });
  expect(send).not.toHaveBeenCalled();
});

test("an upload that fails on the way to the server says so instead of throwing", async () => {
  const send = mock(async () => {
    throw new Error("Failed to fetch");
  });

  expect(await sendGameImage(send, null, form(pngFile()), unusedConvert)).toEqual({
    ok: false,
    message: UPLOAD_MESSAGES.notSent,
  });
});

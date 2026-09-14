import { expect, mock, test } from "bun:test";
import { GAME_BRANDING_MAX_BYTES } from "@/lib/api/gameBranding";
import { refuseImageFile, sendGameImage, UPLOAD_MESSAGES } from "./imageUpload";

function form(file?: File): FormData {
  const data = new FormData();
  data.set("shortname", "BA");
  data.set("kind", "banner");
  if (file) data.set("image", file);
  return data;
}

function bytes(count: number): File {
  return new File([new Uint8Array(count)], "banner.png", { type: "image/png" });
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

test("a file over the limit is never sent, so the server action body limit is never reached", async () => {
  const send = mock(async () => ({ ok: true, message: "sent" }));

  const state = await sendGameImage(send, null, form(bytes(1_200_928)));

  expect(send).not.toHaveBeenCalled();
  expect(state.message).toContain("1173 KB");
});

test("a file within the limit is sent and the action's answer is shown", async () => {
  const send = mock(async () => ({ ok: true, message: "Banner uploaded." }));

  expect(await sendGameImage(send, null, form(bytes(100)))).toEqual({ ok: true, message: "Banner uploaded." });
  expect(send).toHaveBeenCalledTimes(1);
});

test("an upload that fails on the way to the server says so instead of throwing", async () => {
  const send = mock(async () => {
    throw new Error("Failed to fetch");
  });

  expect(await sendGameImage(send, null, form(bytes(100)))).toEqual({
    ok: false,
    message: UPLOAD_MESSAGES.notSent,
  });
});

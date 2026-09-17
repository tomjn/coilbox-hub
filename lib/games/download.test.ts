import { expect, test } from "bun:test";
import { downloadHref, DOWNLOAD_KINDS, parseDownload } from "./download";

test("the three kinds match the check constraint on the column", () => {
  expect(DOWNLOAD_KINDS).toEqual(["rapid", "url", "github"]);
});

test("an empty form clears the download", () => {
  expect(parseDownload("", "")).toEqual({ ok: true, download: null });
  // A kind picked but nothing typed is somebody who changed their mind, not a
  // mistake worth stopping a save for.
  expect(parseDownload("rapid", "   ")).toEqual({ ok: true, download: null });
});

test("a rapid tag is game:branch", () => {
  expect(parseDownload("rapid", "metalfactions:stable")).toEqual({
    ok: true,
    download: { kind: "rapid", value: "metalfactions:stable" },
  });
  expect(parseDownload("rapid", "metalfactions").ok).toBe(false);
  expect(parseDownload("rapid", "a:b:c").ok).toBe(false);
});

test("an address must be http or https", () => {
  expect(parseDownload("url", "https://example.test/game.sdz")).toEqual({
    ok: true,
    download: { kind: "url", value: "https://example.test/game.sdz" },
  });
  expect(parseDownload("url", "ftp://example.test/game.sdz").ok).toBe(false);
  expect(parseDownload("url", "not an address").ok).toBe(false);
  // No script URL ever becomes an href on a page the hub serves.
  expect(parseDownload("url", "javascript:alert(1)").ok).toBe(false);
});

test("a github value is owner/repo", () => {
  expect(parseDownload("github", "Balanced-Annihilation/Balanced-Annihilation")).toEqual({
    ok: true,
    download: { kind: "github", value: "Balanced-Annihilation/Balanced-Annihilation" },
  });
  expect(parseDownload("github", "https://github.com/owner/repo").ok).toBe(false);
  expect(parseDownload("github", "owner").ok).toBe(false);
  expect(parseDownload("github", "owner/repo/extra").ok).toBe(false);
});

test("an unknown kind is refused", () => {
  expect(parseDownload("torrent", "whatever").ok).toBe(false);
});

test("a value longer than the column allows is refused here, not by the database", () => {
  expect(parseDownload("url", `https://example.test/${"a".repeat(600)}`).ok).toBe(false);
});

test("only two of the three kinds have somewhere to click", () => {
  expect(downloadHref({ kind: "url", value: "https://example.test/g.sdz" })).toBe(
    "https://example.test/g.sdz",
  );
  expect(downloadHref({ kind: "github", value: "owner/repo" })).toBe(
    "https://github.com/owner/repo/releases",
  );
  // A rapid tag is a string a lobby hands its own downloader. There is nothing
  // to open.
  expect(downloadHref({ kind: "rapid", value: "ba:stable" })).toBeNull();
});

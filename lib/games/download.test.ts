import { expect, test } from "bun:test";
import {
  downloadHref,
  DOWNLOAD_KINDS,
  MAX_DOWNLOADS,
  parseDownload,
  parseDownloads,
  readDownloads,
} from "./download";

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
  expect(parseDownload("url", "https://example.test/game.sdz", "", "game.sdz")).toEqual({
    ok: true,
    download: { kind: "url", value: "https://example.test/game.sdz", filename: "game.sdz" },
  });
  expect(parseDownload("url", "ftp://example.test/game.sdz", "", "game.sdz").ok).toBe(false);
  expect(parseDownload("url", "not an address", "", "game.sdz").ok).toBe(false);
  // No script URL ever becomes an href on a page the hub serves.
  expect(parseDownload("url", "javascript:alert(1)", "", "game.sdz").ok).toBe(false);
});

test("an address without a filename cannot be fetched, so it is refused", () => {
  // The whole point of the issue: a source coilbox cannot follow to a file is
  // not a download source. A URL need not end in a filename, and the client
  // has nothing to save the bytes as without one.
  expect(parseDownload("url", "https://example.test/download?id=5").ok).toBe(false);
  expect(parseDownload("url", "https://example.test/g.sdz", "", "maps/g.sdz").ok).toBe(false);
  expect(parseDownload("url", "https://example.test/g.sdz", "", "two words.sdz").ok).toBe(false);
});

test("a github value is owner/repo, and the asset is optional", () => {
  expect(parseDownload("github", "Balanced-Annihilation/Balanced-Annihilation")).toEqual({
    ok: true,
    download: { kind: "github", value: "Balanced-Annihilation/Balanced-Annihilation" },
  });
  // Part of the filename, not all of it: FluidPlay/TAP's newest release holds
  // 40 archives and the newest is TAPrime, a different game.
  expect(parseDownload("github", "FluidPlay/TAP", "TAP_v4")).toEqual({
    ok: true,
    download: { kind: "github", value: "FluidPlay/TAP", asset: "TAP_v4" },
  });
  expect(parseDownload("github", "https://github.com/owner/repo").ok).toBe(false);
  expect(parseDownload("github", "owner").ok).toBe(false);
  expect(parseDownload("github", "owner/repo/extra").ok).toBe(false);
});

test("a detail belonging to another kind is dropped rather than refused", () => {
  // The form keeps a row's boxes as somebody switches the kind, so text left
  // over from a kind they moved away from is a stale box and not a mistake.
  expect(parseDownload("rapid", "ba:stable", "some-asset", "some.sdz")).toEqual({
    ok: true,
    download: { kind: "rapid", value: "ba:stable" },
  });
  expect(parseDownload("github", "owner/repo", "", "some.sdz")).toEqual({
    ok: true,
    download: { kind: "github", value: "owner/repo" },
  });
});

test("an unknown kind is refused", () => {
  expect(parseDownload("torrent", "whatever").ok).toBe(false);
});

test("a value longer than the column allows is refused here, not by the database", () => {
  expect(parseDownload("url", `https://example.test/${"a".repeat(600)}`, "", "g.sdz").ok).toBe(
    false,
  );
  expect(parseDownload("github", "owner/repo", "a".repeat(300)).ok).toBe(false);
});

test("a list keeps the order it was given", () => {
  expect(
    parseDownloads(
      JSON.stringify([
        { kind: "github", value: "springraaar/metal_factions", asset: "metal_factions" },
        { kind: "rapid", value: "metalfactions:stable" },
      ]),
    ),
  ).toEqual({
    ok: true,
    downloads: [
      { kind: "github", value: "springraaar/metal_factions", asset: "metal_factions" },
      { kind: "rapid", value: "metalfactions:stable" },
    ],
  });
});

test("an empty row drops out of a list and a bad one stops the save", () => {
  expect(parseDownloads(JSON.stringify([{ kind: "rapid", value: "  " }]))).toEqual({
    ok: true,
    downloads: [],
  });
  const refused = parseDownloads(
    JSON.stringify([{ kind: "rapid", value: "ba:stable" }, { kind: "github", value: "owner" }]),
  );
  expect(refused.ok).toBe(false);
  // Which row, because a page with two sources on it needs to say which one.
  if (!refused.ok) expect(refused.message).toStartWith("Source 2:");
});

test("a list that is not a list, or is too long, is refused", () => {
  expect(parseDownloads("not json").ok).toBe(false);
  expect(parseDownloads('{"kind":"rapid"}').ok).toBe(false);
  expect(parseDownloads("").ok).toBe(true);
  const tooMany = Array.from({ length: MAX_DOWNLOADS + 1 }, () => ({
    kind: "rapid",
    value: "ba:stable",
  }));
  expect(parseDownloads(JSON.stringify(tooMany)).ok).toBe(false);
});

test("reading stored sources is not the same job as writing them", () => {
  // A url source carried over from the single column has no filename. The form
  // would refuse it today, and it is still the address its owner recorded, so
  // the page shows it rather than losing it.
  expect(readDownloads([{ kind: "url", value: "https://example.test/g.sdz" }])).toEqual([
    { kind: "url", value: "https://example.test/g.sdz" },
  ]);
  // A kind nothing knows what to do with still goes, since the value means
  // nothing without one.
  expect(
    readDownloads([{ kind: "torrent", value: "x" }, { kind: "rapid", value: "" }, "junk", null]),
  ).toEqual([]);
  expect(readDownloads(null)).toEqual([]);
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

import { expect, test } from "bun:test";
import { type MapMirrorHost, mirrorLinks, mirrorUrl } from "./mirrors";

const COMET = "Comet Catcher Remake 1.8";

function host(overrides: Partial<MapMirrorHost> = {}): MapMirrorHost {
  return {
    name: "hakora",
    url_template: "http://hakora.xyz/files/springrts/maps/{filename}",
    enabled: true,
    ...overrides,
  };
}

const HELD = { mapName: COMET, archiveFilename: "comet_catcher_remake_1.8.sd7" };

/** Off rather than deleted is the whole point of the column, so a host turned
 *  off has to stop being offered rather than merely stop being first. */
test("a disabled host renders no link", () => {
  expect(mirrorLinks([host({ enabled: false })], HELD)).toEqual([]);
});

test("an enabled host renders one link per map, in the order it was given", () => {
  const links = mirrorLinks([host(), host({ name: "elsewhere" })], HELD);

  expect(links).toEqual([
    {
      name: "hakora",
      url: "http://hakora.xyz/files/springrts/maps/comet_catcher_remake_1.8.sd7",
    },
    {
      name: "elsewhere",
      url: "http://hakora.xyz/files/springrts/maps/comet_catcher_remake_1.8.sd7",
    },
  ]);
});

/**
 * Map names carry spaces as a matter of course and filenames sometimes do too. A
 * raw space in a URL is broken in a way that reads as the mirror's fault rather
 * than the hub's.
 */
test("a map name with a space is encoded rather than pasted into the URL", () => {
  expect(mirrorUrl("https://example.test/search?q={springname}", HELD)).toBe(
    "https://example.test/search?q=Comet%20Catcher%20Remake%201.8",
  );
});

test("and so is a filename", () => {
  expect(
    mirrorUrl("https://example.test/{filename}", {
      mapName: COMET,
      archiveFilename: "comet catcher [v2].sd7",
    }),
  ).toBe("https://example.test/comet%20catcher%20%5Bv2%5D.sd7");
});

/**
 * The link would otherwise point at the host's directory listing under words
 * saying to look for this map there, which sends the reader somewhere real that
 * is not about their map.
 */
test("a map with no archive filename gets no link from a template that needs one", () => {
  const nameless = { mapName: COMET, archiveFilename: null };

  expect(mirrorUrl("http://hakora.xyz/files/springrts/maps/{filename}", nameless)).toBeNull();
  expect(mirrorLinks([host()], nameless)).toEqual([]);
});

/** A blank filename is the same absence as a null one, and it satisfies every
 *  check the column has. */
test("a blank archive filename counts as no filename", () => {
  expect(mirrorLinks([host()], { mapName: COMET, archiveFilename: "  " })).toEqual([]);
});

/** The name is identity and is always there, so a search template still answers
 *  for a map the hub holds no filename for. */
test("a template that needs only the name still renders without a filename", () => {
  const links = mirrorLinks(
    [host({ name: "springfiles", url_template: "https://example.test/?q={springname}" })],
    { mapName: COMET, archiveFilename: null },
  );

  expect(links).toEqual([
    { name: "springfiles", url: "https://example.test/?q=Comet%20Catcher%20Remake%201.8" },
  ]);
});

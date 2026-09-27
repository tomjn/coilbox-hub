import { expect, test } from "bun:test";
import type { MapPack } from "@/lib/maps/packs";
import { buildMapPackListBody, MAP_PACK_LIST_FORMAT, MAP_PACK_LIST_VERSION } from "./mapPackList";

function pack(fields: Partial<MapPack> & Pick<MapPack, "id">): MapPack {
  return {
    title: "BAR maps",
    blurb: null,
    featuredAt: null,
    maps: [{ mapName: "Isis 1.3", slug: "isis-1-3", displayName: null, archiveFilename: null }],
    ...fields,
  };
}

test("the envelope is the one coilbox's readMapPacksBody checks for", () => {
  const body = buildMapPackListBody([]);
  expect(body.format).toBe(MAP_PACK_LIST_FORMAT);
  expect(body.version).toBe(MAP_PACK_LIST_VERSION);
  expect(MAP_PACK_LIST_FORMAT).toBe("coilbox-hub-map-packs");
  expect(MAP_PACK_LIST_VERSION).toBe(1);
  expect(body.packs).toEqual([]);
});

test("a featured pack comes out in coilbox's HubMapPack shape", () => {
  const body = buildMapPackListBody([
    pack({
      id: "9e0f0000-0000-4000-8000-000000000001",
      blurb: "Every map on the mirror",
      featuredAt: "2026-09-27T12:00:00Z",
      maps: [
        {
          mapName: "Comet Catcher Remake 1.8",
          slug: "comet-catcher-remake-1-8",
          displayName: "Comet Catcher",
          archiveFilename: "comet_catcher_remake_1.8.sd7",
        },
      ],
    }),
  ]);

  expect(body.packs).toEqual([
    {
      id: "9e0f0000-0000-4000-8000-000000000001",
      title: "BAR maps",
      blurb: "Every map on the mirror",
      featured: true,
      maps: [
        {
          id: "Comet Catcher Remake 1.8",
          title: "Comet Catcher",
          download: { kind: "map", springName: "Comet Catcher Remake 1.8" },
          filename: "comet_catcher_remake_1.8.sd7",
        },
      ],
    },
  ]);
});

test("a map the catalog no longer holds goes out under its name alone", () => {
  const [row] = buildMapPackListBody([
    pack({
      id: "p",
      maps: [{ mapName: "Isis 1.3", slug: null, displayName: null, archiveFilename: null }],
    }),
  ]).packs;

  expect(row.maps).toEqual([
    { id: "Isis 1.3", title: "Isis 1.3", download: { kind: "map", springName: "Isis 1.3" } },
  ]);
  expect("blurb" in row).toBe(false);
  expect(row.featured).toBe(false);
});

test("an empty pack is left out, and the order given is kept", () => {
  const body = buildMapPackListBody([
    pack({ id: "first" }),
    pack({ id: "empty", maps: [] }),
    pack({ id: "second" }),
  ]);

  expect(body.packs.map((row) => row.id)).toEqual(["first", "second"]);
});

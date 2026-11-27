import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { inflateSync } from "node:zlib";
import { buildAt, type Fetcher, forEachAliveUnit, httpFetcher, readHeader, teamAt, Track, TrackError, transformAt, type UnitPlace, windowOfFrame } from "./track";

// `sf-tumult.cbtrack` is the track Coilbox recorded of `sf-tumult.sdfz`, and
// `sf-tumult.events.jsonl` the log its analysis wrote of the same match, so
// the two say where the same units were.
const bytes = readFileSync(new URL("../../../tests/Fixtures/replays/sf-tumult.cbtrack", import.meta.url));
const file = bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) as ArrayBuffer;
const inflate = async (chunk: Uint8Array) => {
  const out = inflateSync(chunk);
  return out.buffer.slice(out.byteOffset, out.byteOffset + out.byteLength) as ArrayBuffer;
};

/** Serves the file and records each range asked for. */
function served(): { fetcher: Fetcher; ranges: [number, number][] } {
  const ranges: [number, number][] = [];
  return {
    ranges,
    fetcher: async (start, end) => {
      ranges.push([start, end]);
      return file.slice(start, end + 1);
    },
  };
}

test("the header, teams and map are read from the real track", async () => {
  const track = await Track.open(served().fetcher, inflate);

  expect(track.header).toMatchObject({ recorderVersion: 5, framesPerSecond: 30, firstFrame: 0, lastFrame: 15054, windowLength: 900, windowCount: 17, positionShift: 3, rotationBits: 13 });
  // Team 2 is Gaia, the log's `gaiaTeam`, on an ally team of its own.
  expect(track.info.teams.map((t) => [t.id, t.allyTeam])).toEqual([[0, 0], [1, 1], [2, 2]]);
  expect([track.info.mapWidth, track.info.mapHeight]).toEqual([7168, 7168]);
  expect(track.info.footprintAcross?.length).toBe(track.info.unitDefs.length);
});

test("opening reads the header, index and info, and a window is one more range", async () => {
  const { fetcher, ranges } = served();
  const track = await Track.open(fetcher, inflate);
  expect(ranges.length).toBe(3);

  await track.window(4);
  await track.window(4);
  expect(ranges.length).toBe(4);
  // Nothing read is past the window asked for.
  expect(Math.max(...ranges.map(([, end]) => end))).toBeLessThan(file.byteLength - 1);
});

test("a unit is where the analysis log says it was", async () => {
  const track = await Track.open(served().fetcher, inflate);
  // `start_unit_position` at frame 3120: team 0's start unit at (588.0, 5954.7).
  const frame = 3120;
  const w = await track.window(windowOfFrame(track.header, frame));
  const place: UnitPlace = { x: 0, z: 0, fx: 0, fz: 0 };
  let nearest = Infinity;
  forEachAliveUnit(w, frame, (unit) => {
    if (teamAt(w, unit, frame) !== 0 || !transformAt(w, unit, frame, place)) return;
    nearest = Math.min(nearest, Math.hypot(place.x - 588.0, place.z - 5954.7));
  });
  // The log rounds to a tenth of an elmo and the track keeps within half an elmo.
  expect(nearest).toBeLessThan(0.7);
});

test("every unit alive in every window has a place on the map and a build progress", async () => {
  const track = await Track.open(served().fetcher, inflate);
  const place: UnitPlace = { x: 0, z: 0, fx: 0, fz: 0 };
  let seen = 0;
  for (let k = 0; k < track.header.windowCount; k++) {
    const w = await track.window(k);
    const frame = Math.floor((w.startFrame + w.endFrame) / 2);
    forEachAliveUnit(w, frame, (unit) => {
      expect(transformAt(w, unit, frame, place)).toBe(true);
      expect(place.x).toBeGreaterThanOrEqual(0);
      expect(place.x).toBeLessThanOrEqual(7168);
      expect(place.z).toBeGreaterThanOrEqual(0);
      expect(place.z).toBeLessThanOrEqual(7168);
      expect(Math.hypot(place.fx, place.fz)).toBeLessThanOrEqual(1.0001);
      const build = buildAt(w, unit, frame);
      expect(build).toBeGreaterThanOrEqual(0);
      expect(build).toBeLessThanOrEqual(1);
      seen++;
    });
  }
  expect(seen).toBeGreaterThan(17);
});

test("only the last three windows asked for stay decoded", async () => {
  const track = await Track.open(served().fetcher, inflate);
  for (const k of [0, 1, 2, 3]) await track.window(k);
  expect([0, 1, 2, 3].map((k) => track.peek(k) !== undefined)).toEqual([false, true, true, true]);
});

test("a file that is not a track is refused by name", () => {
  const bad = file.slice(0, 96);
  new Uint8Array(bad).set([0x58], 0);
  expect(() => readHeader(bad)).toThrow(TrackError);
  expect(() => readHeader(bad)).toThrow('The track\'s magic is "XBTK", not CBTK.');
});

test("ranges are asked for by header, and a server that ignores them is read once", async () => {
  const asked: (string | null)[] = [];
  const answer = (status: number, body: ArrayBuffer) => new Response(body, { status });
  const ranged = httpFetcher("https://mirror.test/t.cbtrack", async (_url, init) => {
    asked.push(new Headers(init.headers).get("Range"));
    return answer(206, file.slice(0, 96));
  });
  expect((await ranged(0, 95)).byteLength).toBe(96);
  expect(asked).toEqual(["bytes=0-95"]);

  let calls = 0;
  const whole = httpFetcher("https://mirror.test/t.cbtrack", async () => {
    calls++;
    return answer(200, file);
  });
  expect(new Uint8Array(await whole(4, 7))).toEqual(new Uint8Array(file.slice(4, 8)));
  await whole(100, 200);
  expect(calls).toBe(1);

  const refused = httpFetcher("https://mirror.test/t.cbtrack", async () => answer(404, new ArrayBuffer(0)));
  expect(refused(0, 95)).rejects.toThrow("The mirror answered 404 for the track.");
});

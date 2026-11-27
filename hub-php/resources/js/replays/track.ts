/**
 * A replay track (`.cbtrack`) read in the browser one window at a time (#668),
 * from the format in Coilbox's `docs/reference/replay-track-format.md`.
 *
 * Coilbox's own map view reads windows its Rust side has decoded. The hub has
 * no Rust side, so this reads the file itself: the header and index first,
 * then the info chunk, then each window's main chunk as the view asks for it,
 * each by a byte range. Only the columns a top down view of units draws are
 * decoded: a unit's place, facing, build progress and team. The curves are
 * Coilbox's `replayTrack/curves.ts`, in the same order.
 *
 * Typed arrays read in the machine's byte order and the file is little
 * endian, as every machine a browser runs on is.
 */

export const HEADER_BYTES = 96;
const MAGIC = "CBTK";
const FORMAT_VERSION = 1;
const INDEX_ENTRY = 16;
const BLOCK = 64;
const ESCAPE = -32768;
const FRACTION_FULL = 65_535;

/** Fetches bytes `start` to `end`, both included. */
export type Fetcher = (start: number, end: number) => Promise<ArrayBuffer>;
/** Decompresses one zlib stream. */
export type Inflate = (bytes: Uint8Array) => Promise<ArrayBuffer>;

export class TrackError extends Error {}

export interface TrackHeader {
  recorderVersion: number;
  framesPerSecond: number;
  firstFrame: number;
  lastFrame: number;
  windowLength: number;
  windowCount: number;
  positionShift: number;
  rotationBits: number;
  headerSize: number;
  infoBlock: number;
  infoLength: number;
}

export interface TrackTeam {
  id: number;
  allyTeam: number;
  colour: [number, number, number];
}

export interface TrackInfo {
  unitDefs: string[];
  /** Footprint across and along each unit definition in elmos, or null in a track from before recorder version 3. */
  footprintAcross: Uint16Array | null;
  footprintAlong: Uint16Array | null;
  teams: TrackTeam[];
  /** Elmos, or 0 when the track does not say. */
  mapWidth: number;
  mapHeight: number;
}

/** One window's units, as flat arrays. Frames are absolute. */
export interface TrackWindow {
  index: number;
  startFrame: number;
  endFrame: number;
  rotationBits: number;
  unitCount: number;
  unitSerial: Uint32Array;
  unitDef: Uint16Array;
  /** The team at the unit's first frame in the window. */
  unitTeam: Uint8Array;
  unitFirst: Uint32Array;
  unitLast: Uint32Array;
  /** `unitCount + 1` entries each. A unit's keys are from its start to the next unit's. */
  unitTransformStart: Uint32Array;
  unitStatusStart: Uint32Array;
  unitTeamKeyStart: Uint32Array;
  transformFrame: Uint32Array;
  /** Elmos. */
  transformX: Float32Array;
  transformZ: Float32Array;
  rotationA: Int16Array;
  rotationB: Int16Array;
  rotationC: Int16Array;
  rotationDropped: Uint8Array;
  statusFrame: Uint32Array;
  statusBuild: Uint16Array;
  teamKeyFrame: Uint32Array;
  teamKeyTeam: Uint8Array;
}

type Column = Uint8Array | Uint16Array | Int16Array | Uint32Array | Int32Array | Float32Array;

/** Reads the header, the first 96 bytes. */
export function readHeader(bytes: ArrayBuffer): TrackHeader {
  if (bytes.byteLength < HEADER_BYTES) throw new TrackError("The track is shorter than its header.");
  const v = new DataView(bytes);
  const magic = String.fromCharCode(v.getUint8(0), v.getUint8(1), v.getUint8(2), v.getUint8(3));
  if (magic !== MAGIC) throw new TrackError(`The track's magic is ${JSON.stringify(magic)}, not ${MAGIC}.`);
  const format = v.getUint16(4, true);
  if (format !== FORMAT_VERSION) throw new TrackError(`The track is format version ${format}, and this reader reads ${FORMAT_VERSION}.`);
  const header: TrackHeader = {
    headerSize: v.getUint16(6, true),
    recorderVersion: v.getUint16(8, true),
    framesPerSecond: v.getUint16(10, true),
    firstFrame: v.getUint32(12, true),
    lastFrame: v.getUint32(16, true),
    windowLength: v.getUint32(20, true),
    windowCount: v.getUint32(24, true),
    positionShift: v.getUint8(32),
    rotationBits: v.getUint8(34),
    infoBlock: v.getUint32(36, true),
    infoLength: v.getUint32(40, true),
  };
  if (header.headerSize < HEADER_BYTES || header.windowLength < 1 || header.windowCount < 1 || header.framesPerSecond < 1) {
    throw new TrackError("The track's header does not describe any windows.");
  }
  return header;
}

/** Each window's main chunk as [offset, length] pairs, from the index. */
export function readIndex(bytes: ArrayBuffer, count: number): Uint32Array {
  if (bytes.byteLength < count * INDEX_ENTRY) throw new TrackError("The track's window index is cut short.");
  const v = new DataView(bytes);
  const out = new Uint32Array(count * 2);
  for (let k = 0; k < count; k++) {
    out[k * 2] = v.getUint32(k * INDEX_ENTRY, true) * BLOCK;
    out[k * 2 + 1] = v.getUint32(k * INDEX_ENTRY + 4, true);
  }
  return out;
}

/** A decompressed chunk's columns by id. A column of a type this does not know is left out. */
export function readColumns(chunk: ArrayBuffer): Map<number, Column> {
  const v = new DataView(chunk);
  const count = v.getUint32(0, true);
  const out = new Map<number, Column>();
  for (let i = 0; i < count; i++) {
    const at = 4 + i * 12;
    const id = v.getUint16(at, true);
    const type = v.getUint16(at + 2, true);
    const offset = v.getUint32(at + 4, true);
    const n = v.getUint32(at + 8, true);
    const make = [null, Uint8Array, Uint16Array, Int16Array, Uint32Array, Int32Array, Float32Array][type];
    if (!make) continue;
    if (offset + n * make.BYTES_PER_ELEMENT > chunk.byteLength) throw new TrackError(`Column ${id} runs past the end of its chunk.`);
    out.set(id, new make(chunk, offset, n));
  }
  return out;
}

function strings(columns: Map<number, Column>, lengths: number, bytes: number): string[] {
  const sizes = columns.get(lengths);
  const text = columns.get(bytes);
  if (!sizes || !text) return [];
  const decoder = new TextDecoder();
  const out: string[] = [];
  let at = 0;
  for (const size of sizes) {
    out.push(decoder.decode(text.subarray(at, at + size) as Uint8Array));
    at += size;
  }
  return out;
}

export function readInfo(chunk: ArrayBuffer): TrackInfo {
  const c = readColumns(chunk);
  const ids = c.get(172);
  const allies = c.get(173);
  const colours = c.get(175);
  const teams: TrackTeam[] = [];
  for (let i = 0; i < (ids?.length ?? 0); i++) {
    teams.push({
      id: ids![i],
      allyTeam: allies?.[i] ?? 0,
      colour: [colours?.[i * 3] ?? 148, colours?.[i * 3 + 1] ?? 156, colours?.[i * 3 + 2] ?? 170],
    });
  }
  const map = c.get(180);
  return {
    unitDefs: strings(c, 162, 163),
    footprintAcross: (c.get(181) as Uint16Array | undefined) ?? null,
    footprintAlong: (c.get(182) as Uint16Array | undefined) ?? null,
    teams,
    mapWidth: map?.[0] ?? 0,
    mapHeight: map?.[1] ?? 0,
  };
}

/** The first frame of window `k`, and its last. */
export function windowFrames(h: TrackHeader, k: number): [number, number] {
  const start = h.firstFrame + k * h.windowLength;
  return [start, Math.min(start + h.windowLength, h.lastFrame)];
}

/** The window holding `frame`, the last when it is past the end. */
export function windowOfFrame(h: TrackHeader, frame: number): number {
  const k = Math.floor((frame - h.firstFrame) / h.windowLength);
  return Math.min(Math.max(k, 0), h.windowCount - 1);
}

/** A window's units and their keys, from its decompressed main chunk. */
export function readWindow(chunk: ArrayBuffer, h: TrackHeader, k: number): TrackWindow {
  const c = readColumns(chunk);
  const [startFrame, endFrame] = windowFrames(h, k);
  const serial = (c.get(2) as Uint32Array | undefined) ?? new Uint32Array(0);
  const n = serial.length;
  const counts = (id: number) => c.get(id) ?? new Uint8Array(n);
  const starts = (per: Column) => {
    const out = new Uint32Array(n + 1);
    for (let i = 0; i < n; i++) out[i + 1] = out[i] + per[i];
    return out;
  };
  const first = counts(6);
  const last = counts(7);
  const unitFirst = new Uint32Array(n);
  const unitLast = new Uint32Array(n);
  for (let i = 0; i < n; i++) {
    unitFirst[i] = startFrame + first[i];
    unitLast[i] = startFrame + last[i];
  }
  const unitTransformStart = starts(counts(8));
  const unitStatusStart = starts(counts(9));
  const unitTeamKeyStart = starts(counts(13));

  // Times are frames since the curve's key before, the first from the window's start.
  const times = (id: number, unitStart: Uint32Array) => {
    const column = c.get(id) ?? new Uint16Array(0);
    const out = new Uint32Array(unitStart[n]);
    if (column.length < out.length) throw new TrackError(`Column ${id} has fewer keys than the units count.`);
    for (let i = 0; i < n; i++) {
      let frame = startFrame;
      for (let j = unitStart[i]; j < unitStart[i + 1]; j++) out[j] = frame += column[j];
    }
    return out;
  };
  // A difference with an escape: the narrow column, or the next wide value when it says -32768.
  const step = 1 / 2 ** h.positionShift;
  const positions = (narrowId: number, wideId: number) => {
    const narrow = c.get(narrowId) ?? new Int16Array(0);
    const wide = c.get(wideId) ?? new Int32Array(0);
    const out = new Float32Array(unitTransformStart[n]);
    let next = 0;
    for (let i = 0; i < n; i++) {
      let value = 0;
      for (let j = unitTransformStart[i]; j < unitTransformStart[i + 1]; j++) {
        const d = narrow[j];
        if (d === ESCAPE) {
          if (next >= wide.length) throw new TrackError(`Column ${wideId} ran out of wide values.`);
          value = wide[next++];
        } else {
          value += d;
        }
        out[j] = value * step;
      }
    }
    return out;
  };
  // A wrapping difference, starting from 0 for each unit.
  const wrapping = <T extends Int16Array | Uint16Array>(id: number, unitStart: Uint32Array, out: T): T => {
    const column = c.get(id) ?? new Uint16Array(out.length);
    for (let i = 0; i < n; i++) {
      let value = 0;
      for (let j = unitStart[i]; j < unitStart[i + 1]; j++) out[j] = value = (value + column[j]) & 0xffff;
    }
    return out;
  };
  const keys = unitTransformStart[n];

  return {
    index: k,
    startFrame,
    endFrame,
    rotationBits: h.rotationBits,
    unitCount: n,
    unitSerial: serial,
    unitDef: (c.get(3) as Uint16Array | undefined) ?? new Uint16Array(n),
    unitTeam: (c.get(4) as Uint8Array | undefined) ?? new Uint8Array(n),
    unitFirst,
    unitLast,
    unitTransformStart,
    unitStatusStart,
    unitTeamKeyStart,
    transformFrame: times(16, unitTransformStart),
    transformX: positions(17, 20),
    transformZ: positions(19, 22),
    // An Int16Array stores a value of 32,768 or more as that minus 65,536, which is how the format signs them.
    rotationA: wrapping(24, unitTransformStart, new Int16Array(keys)),
    rotationB: wrapping(25, unitTransformStart, new Int16Array(keys)),
    rotationC: wrapping(26, unitTransformStart, new Int16Array(keys)),
    rotationDropped: (c.get(23) as Uint8Array | undefined) ?? new Uint8Array(keys).fill(3),
    statusFrame: times(32, unitStatusStart),
    statusBuild: wrapping(34, unitStatusStart, new Uint16Array(unitStatusStart[n])),
    teamKeyFrame: times(14, unitTeamKeyStart),
    teamKeyTeam: (c.get(15) as Uint8Array | undefined) ?? new Uint8Array(unitTeamKeyStart[n]),
  };
}

/** How many of `frames[lo..hi]` are at or before `frame`. */
function countAtOrBefore(frames: Uint32Array, lo: number, hi: number, frame: number): number {
  let from = lo;
  let to = hi;
  while (from < to) {
    const mid = (from + to) >>> 1;
    if (frames[mid] <= frame) from = mid + 1;
    else to = mid;
  }
  return from - lo;
}

const span = { a: 0, b: -1, s: 0 };

function locate(frames: Uint32Array, lo: number, hi: number, frame: number): boolean {
  if (lo >= hi) return false;
  const after = countAtOrBefore(frames, lo, hi, frame);
  if (after === 0) {
    span.a = lo;
    span.b = -1;
    return true;
  }
  const a = lo + after - 1;
  const b = lo + after;
  span.a = a;
  span.b = b < hi && frames[a] !== frame ? b : -1;
  if (span.b >= 0) span.s = (frame - frames[a]) / (frames[b] - frames[a]);
  return true;
}

const qa = [0, 0, 0, 0];
const qb = [0, 0, 0, 0];

function rotationValue(w: TrackWindow, i: number, out: number[]): void {
  const scale = ((1 << (w.rotationBits - 1)) - 1) / Math.SQRT1_2;
  const k = [w.rotationA[i] / scale, w.rotationB[i] / scale, w.rotationC[i] / scale];
  const dropped = Math.sqrt(Math.max(1 - (k[0] * k[0] + k[1] * k[1] + k[2] * k[2]), 0));
  let next = 0;
  for (let c = 0; c < 4; c++) out[c] = c === w.rotationDropped[i] ? dropped : k[next++];
}

export interface UnitPlace {
  x: number;
  z: number;
  /** Where the unit's front points on the map. */
  fx: number;
  fz: number;
}

/** `transformAt`: unit `unit`'s place and facing at `frame`. False when it has no keys. */
export function transformAt(w: TrackWindow, unit: number, frame: number, out: UnitPlace): boolean {
  if (!locate(w.transformFrame, w.unitTransformStart[unit], w.unitTransformStart[unit + 1], frame)) return false;
  const { a, b, s } = span;
  rotationValue(w, a, qa);
  let q = qa;
  if (b < 0) {
    out.x = w.transformX[a];
    out.z = w.transformZ[a];
  } else {
    rotationValue(w, b, qb);
    out.x = w.transformX[a] + (w.transformX[b] - w.transformX[a]) * s;
    out.z = w.transformZ[a] + (w.transformZ[b] - w.transformZ[a]) * s;
    const sign = qa[0] * qb[0] + qa[1] * qb[1] + qa[2] * qb[2] + qa[3] * qb[3] < 0 ? -1 : 1;
    const blend = [0, 1, 2, 3].map((c) => qa[c] * (1 - s) + sign * qb[c] * s);
    const length = Math.hypot(...blend);
    if (length > 0) q = blend.map((v) => v / length);
  }
  const [x, y, z, ww] = q;
  out.fx = 2 * (x * z + ww * y);
  out.fz = 1 - 2 * (x * x + y * y);
  return true;
}

/** Build progress at `frame`, 0 to 1, in a straight line between keys. 1 when the unit has no status keys. */
export function buildAt(w: TrackWindow, unit: number, frame: number): number {
  if (!locate(w.statusFrame, w.unitStatusStart[unit], w.unitStatusStart[unit + 1], frame)) return 1;
  const { a, b, s } = span;
  const at = b < 0 ? w.statusBuild[a] : w.statusBuild[a] + (w.statusBuild[b] - w.statusBuild[a]) * s;
  return at / FRACTION_FULL;
}

/** The unit's team at `frame`: its last team key at or before it, else its team at the window's start. */
export function teamAt(w: TrackWindow, unit: number, frame: number): number {
  const lo = w.unitTeamKeyStart[unit];
  const after = countAtOrBefore(w.teamKeyFrame, lo, w.unitTeamKeyStart[unit + 1], frame);
  return after === 0 ? w.unitTeam[unit] : w.teamKeyTeam[lo + after - 1];
}

/** Calls `visit` with each unit alive at `frame`. */
export function forEachAliveUnit(w: TrackWindow, frame: number, visit: (unit: number) => void): void {
  for (let i = 0; i < w.unitCount; i++) {
    if (w.unitFirst[i] <= frame && w.unitLast[i] >= frame) visit(i);
  }
}

/** Windows kept decoded: the one shown and the ones either side of it. */
const KEPT_WINDOWS = 3;

/**
 * An open track. It holds the header, the index and the info, and keeps the
 * last few windows it decoded. A window is fetched and decoded once however
 * often it is asked for while it loads.
 */
export class Track {
  private readonly windows = new Map<number, TrackWindow>();
  private readonly loading = new Map<number, Promise<TrackWindow>>();

  private constructor(
    readonly header: TrackHeader,
    readonly info: TrackInfo,
    private readonly index: Uint32Array,
    private readonly fetcher: Fetcher,
    private readonly inflate: Inflate,
  ) {}

  static async open(fetcher: Fetcher, inflate: Inflate): Promise<Track> {
    const header = readHeader(await fetcher(0, HEADER_BYTES - 1));
    const indexBytes = header.windowCount * INDEX_ENTRY;
    const index = readIndex(await fetcher(header.headerSize, header.headerSize + indexBytes - 1), header.windowCount);
    const infoStart = header.infoBlock * BLOCK;
    const info = readInfo(await inflate(new Uint8Array(await fetcher(infoStart, infoStart + header.infoLength - 1))));
    return new Track(header, info, index, fetcher, inflate);
  }

  /** The window if it is decoded, else undefined. */
  peek(k: number): TrackWindow | undefined {
    return this.windows.get(k);
  }

  window(k: number): Promise<TrackWindow> {
    const kept = this.windows.get(k);
    if (kept) return Promise.resolve(kept);
    let pending = this.loading.get(k);
    if (!pending) {
      pending = this.load(k).finally(() => this.loading.delete(k));
      this.loading.set(k, pending);
    }
    return pending;
  }

  private async load(k: number): Promise<TrackWindow> {
    const offset = this.index[k * 2];
    const length = this.index[k * 2 + 1];
    const chunk = await this.inflate(new Uint8Array(await this.fetcher(offset, offset + length - 1)));
    const w = readWindow(chunk, this.header, k);
    this.windows.set(k, w);
    // Forget the window furthest from this one.
    while (this.windows.size > KEPT_WINDOWS) {
      let far = k;
      for (const held of this.windows.keys()) if (Math.abs(held - k) > Math.abs(far - k)) far = held;
      this.windows.delete(far);
    }
    return w;
  }
}

/**
 * Fetches byte ranges of a track at `url`. A server that ignores the range and
 * sends the whole file is read from that one copy after.
 */
export function httpFetcher(url: string, fetchImpl: typeof fetch = fetch): Fetcher {
  let whole: Promise<ArrayBuffer> | null = null;
  return async (start, end) => {
    if (whole) return (await whole).slice(start, end + 1);
    const response = await fetchImpl(url, { headers: { Range: `bytes=${start}-${end}` } });
    if (response.status === 206) return response.arrayBuffer();
    if (response.status === 200) {
      whole = response.arrayBuffer();
      return (await whole).slice(start, end + 1);
    }
    throw new TrackError(`The mirror answered ${response.status} for the track.`);
  };
}

/** Decompresses a zlib stream with the browser's own `DecompressionStream`. */
export const browserInflate: Inflate = async (bytes) => {
  const stream = new Blob([bytes as BlobPart]).stream().pipeThrough(new DecompressionStream("deflate"));
  return new Response(stream).arrayBuffer();
};

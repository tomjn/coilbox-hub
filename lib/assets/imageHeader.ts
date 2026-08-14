/**
 * What the bytes of an upload actually are, read out of the first few kilobytes
 * (issue #105).
 *
 * Format only. Nothing here knows what a buildpic is or how big one may be:
 * `./caps` holds the policy and this holds the parsing, so a rule can change
 * without anyone touching a byte offset.
 *
 * ## Why a header parser and not an image library
 *
 * `sharp` is present in `node_modules`, but as an optional native dependency of
 * `next` rather than a dependency of this project. Using it in a route would
 * make a request path depend on another package's install tree, and declaring
 * it properly pulls a native module and a platform binary per architecture into
 * the function bundle for four numbers.
 *
 * Those four numbers are all in the first header of both formats: PNG puts them
 * in IHDR at a fixed offset, and WebP puts them in the first RIFF chunk. Neither
 * needs a decoder, which also means untrusted bytes never reach one.
 *
 * The parse is deliberately shallow. It reads what the file says about itself
 * and does not verify that the rest of the file agrees, because the alternative
 * is a decode. A file that lies past its header is a picture that fails to
 * render, which the moderation queue catches. A file that lies in its header is
 * refused here.
 */

/**
 * How much of the file to hand {@link readImageHeader}.
 *
 * Everything it reads is inside the first 32 bytes of a plain file. The margin
 * is for extended WebP, where the image chunk sits after whatever metadata
 * chunks came first, and it bounds the scan: a file whose image chunk is past
 * this is unreadable rather than worth streaming more of.
 */
export const IMAGE_HEADER_BYTES = 4096;

export interface ImageHeader {
  /** The type the bytes are, which is not necessarily the type declared. */
  mime: "image/png" | "image/webp";
  width: number;
  height: number;
  /**
   * Whether the encoding preserves every sample. PNG always does, and WebP does
   * only in its VP8L mode.
   */
  lossless: boolean;
  /** Bits per channel. Always 8 for WebP, which has no deeper mode. */
  bitDepth: number;
  /** Whether the samples are one channel of luminance, with or without alpha. */
  grayscale: boolean;
}

const PNG_SIGNATURE = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a];

function fourcc(bytes: Uint8Array, at: number): string {
  return String.fromCharCode(bytes[at], bytes[at + 1], bytes[at + 2], bytes[at + 3]);
}

function u32be(bytes: Uint8Array, at: number): number {
  return bytes[at] * 0x1000000 + ((bytes[at + 1] << 16) | (bytes[at + 2] << 8) | bytes[at + 3]);
}

function u32le(bytes: Uint8Array, at: number): number {
  return (
    bytes[at] + bytes[at + 1] * 0x100 + bytes[at + 2] * 0x10000 + bytes[at + 3] * 0x1000000
  );
}

function u24le(bytes: Uint8Array, at: number): number {
  return bytes[at] + bytes[at + 1] * 0x100 + bytes[at + 2] * 0x10000;
}

function u16le(bytes: Uint8Array, at: number): number {
  return bytes[at] + bytes[at + 1] * 0x100;
}

/**
 * PNG. The signature is eight bytes and IHDR is required to be the first chunk,
 * so width, height, bit depth and colour type are at fixed offsets.
 *
 * Colour types 0 and 4 are the grayscale pair, without and with alpha. That
 * distinction matters to `overlay:height` and to nothing else.
 */
function readPng(bytes: Uint8Array): ImageHeader | null {
  if (bytes.length < 26) return null;
  if (PNG_SIGNATURE.some((byte, index) => bytes[index] !== byte)) return null;
  if (fourcc(bytes, 12) !== "IHDR") return null;

  const colourType = bytes[25];

  return {
    mime: "image/png",
    width: u32be(bytes, 16),
    height: u32be(bytes, 20),
    lossless: true,
    bitDepth: bytes[24],
    grayscale: colourType === 0 || colourType === 4,
  };
}

/**
 * The still image chunk of a RIFF container, and where its payload starts.
 *
 * A plain file has it first. An extended file starts with VP8X and may put
 * metadata or an alpha plane before it, so the chunks are walked. An animation
 * has no top level image chunk at all, only frames, and comes back null: the
 * hub stores stills, and measuring a canvas it cannot read a picture out of
 * would accept one.
 */
function findImageChunk(bytes: Uint8Array): { kind: string; at: number } | null {
  let at = 12;

  while (at + 8 <= bytes.length) {
    const kind = fourcc(bytes, at);
    const size = u32le(bytes, at + 4);
    if (kind === "VP8 " || kind === "VP8L") return { kind, at: at + 8 };
    at += 8 + size + (size % 2);
  }

  return null;
}

/**
 * WebP. Three shapes, and the dimensions are in a different place in each.
 *
 * - `VP8 ` is lossy. Past the three byte frame tag and the three byte sync code
 *   are two 14 bit dimensions, each with two scaling bits above it.
 * - `VP8L` is lossless. One signature byte, then 14 bits of width minus one and
 *   14 bits of height minus one, packed little endian.
 * - `VP8X` is the extended container, and its canvas size is what the file is,
 *   whatever the image chunk inside it says.
 */
function readWebp(bytes: Uint8Array): ImageHeader | null {
  if (bytes.length < 30) return null;
  if (fourcc(bytes, 0) !== "RIFF" || fourcc(bytes, 8) !== "WEBP") return null;

  const image = findImageChunk(bytes);
  if (!image) return null;

  const common = {
    mime: "image/webp",
    lossless: image.kind === "VP8L",
    bitDepth: 8,
    grayscale: false,
  } as const;

  if (fourcc(bytes, 12) === "VP8X") {
    return { ...common, width: u24le(bytes, 24) + 1, height: u24le(bytes, 27) + 1 };
  }

  if (image.kind === "VP8L") {
    if (bytes[image.at] !== 0x2f) return null;
    const packed = u32le(bytes, image.at + 1);
    return {
      ...common,
      width: (packed & 0x3fff) + 1,
      height: ((packed >>> 14) & 0x3fff) + 1,
    };
  }

  const sync = image.at + 3;
  if (bytes[sync] !== 0x9d || bytes[sync + 1] !== 0x01 || bytes[sync + 2] !== 0x2a) return null;

  return {
    ...common,
    width: u16le(bytes, image.at + 6) & 0x3fff,
    height: u16le(bytes, image.at + 8) & 0x3fff,
  };
}

/**
 * What the bytes say they are, or null when they are not a PNG or a WebP the
 * hub can measure.
 *
 * Null rather than a throw, and null rather than a guess. The caller turns it
 * into a refusal, which is the honest answer for bytes whose dimensions cannot
 * be established: every cap in `./caps` is a statement about pixels, and none of
 * them can be applied to a file nobody can measure.
 */
export function readImageHeader(bytes: Uint8Array): ImageHeader | null {
  const header = readPng(bytes) ?? readWebp(bytes);
  if (!header) return null;

  return header.width > 0 && header.height > 0 ? header : null;
}

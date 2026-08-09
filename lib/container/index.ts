/**
 * The coilbox container format, as the app defines it.
 *
 * Import from here rather than from `./container` directly. That file is
 * vendored verbatim from `src/container/container.ts` in tomjn/coilbox and is
 * not ours to edit: `bun run sync:container` is the only thing that writes it,
 * and `bun run check:container` fails if it has been changed here or if coilbox
 * has moved on. The pinned source is recorded in `source.json`.
 *
 * The hub has to read exactly what the app writes. A divergence between the two
 * would not throw anywhere, it would just mean the gallery accepts an item that
 * coilbox then refuses to import, which surfaces as a user reporting a broken
 * link rather than as a failing build.
 *
 * Change the format in coilbox, then sync it here.
 */

export {
  asContainer,
  base64UrlDecode,
  base64UrlEncode,
  CONTAINER_FORMAT,
  CONTAINER_KINDS,
  CONTAINER_VERSION,
  COMPRESSED_CODE_PREFIX,
  decodeContainerText,
  encodeContainerCode,
  encodeContainerJson,
  identify,
  makeContainer,
  readContainer,
  sniffPayloadKind,
  SUPPORTED_KIND_VERSIONS,
} from "./container";

export type {
  Compatibility,
  Container,
  ContainerKind,
  Identification,
  OpenError,
  OpenResult,
} from "./container";

/**
 * Kinds the gallery carries. Coilbox understands five, but campaigns are out of
 * v1: they inline images and audio as base64 data URIs, which puts them past the
 * import ceiling below. See the design doc in coilbox for the reasoning.
 */
export const GALLERY_KINDS = [
  "preset",
  "challenge",
  "setup-pack",
  "scenario",
] as const;

export type GalleryKind = (typeof GALLERY_KINDS)[number];

/**
 * Largest container the app will import, from `MAX_IMPORT_BYTES` in
 * `src/deeplink/fetchImport.ts` and `MAX_INFLATED_BYTES` in `container.ts`.
 * Publishing anything larger would hand out a link that cannot be opened, so the
 * gallery refuses it at the door.
 */
export const MAX_CONTAINER_BYTES = 512 * 1024;

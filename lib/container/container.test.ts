import { expect, test } from "bun:test";
import { readdirSync, readFileSync } from "node:fs";
import {
  CONTAINER_KINDS,
  GALLERY_KINDS,
  identify,
  encodeContainerCode,
  MAX_CONTAINER_BYTES,
  SUPPORTED_KIND_VERSIONS,
} from "./index";

/**
 * These cover the seam between the hub and coilbox rather than the container
 * format itself, which has its own tests upstream. What matters here is that the
 * vendored module works in this project at all, and that the assumptions the
 * gallery makes about it are still true after a sync.
 */

test("a code the app would write round-trips here", () => {
  const code = encodeContainerCode("preset", SUPPORTED_KIND_VERSIONS.preset, {
    gameName: "Beyond All Reason",
    mapName: "Comet Catcher Remake",
    startPosType: 2,
    modOptionValues: {},
    participants: [],
  });

  const result = identify(code);
  expect(result.kind).toBe("preset");
  expect(result.compatibility).toBe("ok");
});

test("a container from a newer coilbox is flagged, not misread", () => {
  const code = encodeContainerCode(
    "preset",
    SUPPORTED_KIND_VERSIONS.preset + 1,
    {},
  );

  expect(identify(code).compatibility).toBe("newer");
});

test("every kind the gallery carries still exists upstream", () => {
  for (const kind of GALLERY_KINDS) {
    expect(CONTAINER_KINDS).toContain(kind);
  }
});

test("campaigns are deliberately not carried", () => {
  expect(GALLERY_KINDS as readonly string[]).not.toContain("campaign");
});

/** The kinds `public.item` will store, read from the last statement that sets
 * the check on `kind`.
 *
 * Anchored on a word boundary, because `asset.rejection_kind` (#115) also ends
 * in `kind` and its list is nothing to do with what the gallery carries.
 *
 * Statements rather than whole files, because `map_point.kind` (#182) and
 * `game_download_source.kind` (#396) are spelled the same and answer different
 * questions, and a file can hold one of those beside a mention of
 * `public.item` for an unrelated reason. Only a statement creating or altering
 * the table can set the check on it, so that is what this looks at. */
const KIND_LIST = /\bkind in \(([^)]*)\)/;
const TOUCHES_ITEM = /\b(create|alter) table public\.item\b/;

function kindsTheDatabaseAccepts(): string[] {
  const dir = "supabase/migrations";
  const constraint = readdirSync(dir)
    .sort()
    .flatMap((file) => readFileSync(`${dir}/${file}`, "utf8").split(";"))
    .filter((statement) => TOUCHES_ITEM.test(statement) && KIND_LIST.test(statement))
    .at(-1);
  const list = constraint?.match(KIND_LIST)?.[1] ?? "";
  return [...list.matchAll(/'([^']+)'/g)].map((match) => match[1]);
}

/**
 * `kind` is a literal list in SQL, so a kind added here and not there is
 * accepted by every line of TypeScript and refused by the insert. It fails at
 * publish time, on a real person's share code, having passed CI.
 */
test("the database accepts exactly the kinds the gallery carries", () => {
  expect(kindsTheDatabaseAccepts().sort()).toEqual(
    [...GALLERY_KINDS].sort(),
  );
});

test("the publish ceiling matches the app's import ceiling", () => {
  expect(MAX_CONTAINER_BYTES).toBe(512 * 1024);
});

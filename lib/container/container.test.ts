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

/** The kinds `public.item` will store, read from the last migration that sets
 * the check on `kind`. */
function kindsTheDatabaseAccepts(): string[] {
  const dir = "supabase/migrations";
  const constraint = readdirSync(dir)
    .sort()
    .map((file) => readFileSync(`${dir}/${file}`, "utf8"))
    .filter((sql) => sql.includes("kind in ("))
    .at(-1);
  const list = constraint?.match(/kind in \(([^)]*)\)/)?.[1] ?? "";
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

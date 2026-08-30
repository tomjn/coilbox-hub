import { expect, test } from "bun:test";
import {
  GAME_FACTS_FORMAT,
  GAME_FACTS_MAX_UNITS,
  parseGameFactsBody,
} from "./gameFacts";

/**
 * The wire shape of a game facts submission (#224), and the strictness the map
 * submission already established: an unknown field is a refusal naming it, a
 * malformed entry is refused rather than thrown away with its nine hundred
 * neighbours, and nothing the hub derives itself may arrive from a client.
 */

function body(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    format: GAME_FACTS_FORMAT,
    version: 1,
    shortname: "BA",
    release: "1.9.0",
    units: [{ name: "armcom" }],
    ...overrides,
  };
}

test("a well formed submission parses whole", () => {
  const parsed = parseGameFactsBody(
    body({
      complete: true,
      startUnits: ["armcom"],
      factions: [{ key: "armada", name: "Armada" }],
      units: [
        {
          name: "armcom",
          fullName: "Commander",
          factionKey: "armada",
          buildOptions: ["armsolar", "armsolar", "armmex"],
          stats: { health: 5000 },
        },
      ],
    }),
  );

  expect(parsed.ok).toBe(true);
  if (!parsed.ok) return;
  expect(parsed.submission.shortname).toBe("BA");
  expect(parsed.submission.release).toBe("1.9.0");
  expect(parsed.submission.complete).toBe(true);
  expect(parsed.submission.start_units).toEqual(["armcom"]);
  expect(parsed.submission.factions).toEqual([{ key: "armada", name: "Armada" }]);
  expect(parsed.submission.units[0].build_options).toEqual(["armmex", "armsolar"]);
});

test("the envelope is named before anything else is read", () => {
  expect(parseGameFactsBody(body({ format: "coilbox-hub-maps" })).ok).toBe(false);
  expect(parseGameFactsBody(body({ version: 2 })).ok).toBe(false);
});

test("an unknown field anywhere is a refusal naming it", () => {
  const top = parseGameFactsBody(body({ slug: "ba" }));
  expect(top.ok).toBe(false);
  if (!top.ok) expect(top.error).toContain("slug");

  const unit = parseGameFactsBody(
    body({ units: [{ name: "armcom", full_name: "Commander" }] }),
  );
  expect(unit.ok).toBe(false);
  if (!unit.ok) expect(unit.error).toContain("full_name");

  const faction = parseGameFactsBody(
    body({ factions: [{ key: "armada", name: "Armada", logo: "x" }] }),
  );
  expect(faction.ok).toBe(false);
  if (!faction.ok) expect(faction.error).toContain("logo");
});

test("a digest from a client is an unknown field like any other", () => {
  const parsed = parseGameFactsBody(
    body({ units: [{ name: "armcom", facts_digest: "d" }] }),
  );
  expect(parsed.ok).toBe(false);
  if (!parsed.ok) expect(parsed.error).toContain("facts_digest");
});

test("complete defaults to false, so a partial backfill removes nothing by accident", () => {
  const parsed = parseGameFactsBody(body());
  expect(parsed.ok).toBe(true);
  if (!parsed.ok) return;
  expect(parsed.submission.complete).toBe(false);
});

test("absent factions and start units stay absent rather than becoming empty sets", () => {
  const parsed = parseGameFactsBody(body());
  expect(parsed.ok).toBe(true);
  if (!parsed.ok) return;
  expect(parsed.submission.factions).toBeNull();
  expect(parsed.submission.start_units).toBeNull();
});

test("optional text that is blank arrives as null, and absent stays absent", () => {
  const parsed = parseGameFactsBody(
    body({
      units: [
        { name: "armcom", fullName: "", factionKey: undefined },
        { name: "armmex", fullName: null },
      ],
    }),
  );
  expect(parsed.ok).toBe(true);
  if (!parsed.ok) return;
  for (const unit of parsed.submission.units) {
    expect(unit.full_name).toBeNull();
    expect(unit.faction_key).toBeNull();
  }
});

test("build options are sorted and deduplicated, because order is not a fact", () => {
  const parsed = parseGameFactsBody(
    body({ units: [{ name: "armcom", buildOptions: ["armmex", "armsolar", "armmex"] }] }),
  );
  expect(parsed.ok).toBe(true);
  if (!parsed.ok) return;
  expect(parsed.submission.units[0].build_options).toEqual(["armmex", "armsolar"]);
});

test("stats must be an object of bounded size", () => {
  const ok = parseGameFactsBody(
    body({ units: [{ name: "armcom", stats: { health: 5000 } }] }),
  );
  expect(ok.ok).toBe(true);

  const notObject = parseGameFactsBody(
    body({ units: [{ name: "armcom", stats: [1, 2] }] }),
  );
  expect(notObject.ok).toBe(false);

  const tooBig = parseGameFactsBody(
    body({ units: [{ name: "armcom", stats: { blob: "x".repeat(10_000) } }] }),
  );
  expect(tooBig.ok).toBe(false);
});

test("a request past the unit cap is refused before any entry is read", () => {
  const units = Array.from({ length: GAME_FACTS_MAX_UNITS + 1 }, (_, i) => ({ name: `u${i}` }));
  const parsed = parseGameFactsBody(body({ units }));
  expect(parsed.ok).toBe(false);
  if (!parsed.ok) expect(parsed.error).toContain("at most");
});

test("accepts morph targets on a unit", () => {
  const parsed = parseGameFactsBody(
    body({
      units: [
        {
          name: "armcom",
          buildOptions: [],
          stats: {},
          morphTargets: [{ into: "armcom1", morphtime: 10 }],
        },
      ],
    }),
  );
  expect(parsed.ok).toBe(true);
  if (!parsed.ok) return;
  expect(parsed.submission.units[0].morph_targets).toEqual([
    { into: "armcom1", morphtime: 10 },
  ]);
});

// Amended from the plan's draft, which asserted `parsed.ok === true` with the
// bad unit dropped. `readUnit`'s callers already fail the whole request on any
// unit-level error, the same path `stats` and `buildOptions` take, and that
// batch semantics is not this task's to change. `morphTargets` fails exactly
// the way its neighbours do, and what matters is that the message names the
// field.
test("refuses a morph target with no unit to turn into", () => {
  const parsed = parseGameFactsBody(
    body({
      units: [
        { name: "armcom", buildOptions: [], stats: {}, morphTargets: [{ morphtime: 10 }] },
      ],
    }),
  );
  expect(parsed.ok).toBe(false);
  if (parsed.ok) return;
  expect(parsed.error).toContain("morphTargets");
});

test("refuses morph targets that are not a list", () => {
  const parsed = parseGameFactsBody(
    body({
      units: [
        { name: "armcom", buildOptions: [], stats: {}, morphTargets: { into: "armcom1" } },
      ],
    }),
  );
  expect(parsed.ok).toBe(false);
  if (parsed.ok) return;
  expect(parsed.error).toContain("morphTargets");
});

test("takes a unit that sends no morph targets at all", () => {
  const parsed = parseGameFactsBody(
    body({ units: [{ name: "armcom", buildOptions: [], stats: {} }] }),
  );
  expect(parsed.ok).toBe(true);
  if (!parsed.ok) return;
  expect(parsed.submission.units[0].morph_targets).toEqual([]);
});

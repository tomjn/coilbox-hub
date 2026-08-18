import { expect, test } from "bun:test";
import { MAP_CATALOG_CAPS } from "./catalog";
import catalog from "./vendor/map-catalog.json";

/**
 * Both sides have to agree what a catalog entry is, and a disagreement shows up
 * as facts that look wrong rather than as an error either repo can see (#185).
 * The file is vendored, so upstream moving turns `bun run check:vendor` red.
 * These say what the hub was told the file holds, so a change upstream that
 * dropped a fact or moved a cap has to be read and agreed to rather than
 * arriving with the next sync.
 *
 * Held against the issue rather than against hub code, unlike
 * `lib/assets/vocabulary.test.ts`, because the caps are the only part of the file
 * hub code reads. Everything else has no accessor to be checked against, so the
 * agreement itself is what is written down.
 */

test("the catalog parses and carries an integer version", () => {
  expect(Number.isInteger(catalog.catalogVersion)).toBe(true);
  expect(catalog.catalogVersion).toBe(3);
});

/**
 * The fact list is the whole point of the file: it is what stops one client
 * reporting a size in pixels while another reports elmos, with both looking
 * honest. So the units are checked by name, not just counted.
 */
test("the fact list gives sizes in elmos, wind as a strength and coverage as a share", () => {
  expect(catalog.facts.width_elmos.unit).toBe("elmos");
  expect(catalog.facts.height_elmos.unit).toBe("elmos");
  expect(catalog.facts.world_height_min.unit).toBe("elmos");
  expect(catalog.facts.world_height_max.unit).toBe("elmos");
  expect(catalog.facts.min_wind.unit).toBe("strength");
  expect(catalog.facts.max_wind.unit).toBe("strength");
  expect(catalog.facts.tidal_strength.unit).toBe("strength");
  // A share, so zero to one. The file names the unit and the bound is what the
  // unit means, so this checks the name it is agreed under.
  expect(catalog.facts.water_coverage.unit).toBe("share");
});

/**
 * The facts that identify an entry at all. Without a source archive and its
 * hash there is nothing to say two clients described the same map, and the
 * catalog version is what says they ran the same extraction.
 */
test("the facts that identify an entry are required", () => {
  const required = Object.entries(catalog.facts)
    .filter(([, fact]) => fact.required)
    .map(([name]) => name)
    .sort();

  expect(required).toEqual(
    [
      "catalog_version",
      "height_elmos",
      "map_name",
      "source_archive",
      "source_hash",
      "width_elmos",
      "world_height_max",
      "world_height_min",
    ].sort(),
  );
});

/**
 * Metal spots are the one catalog fact produced by judgement rather than by
 * reading a value. Clustering is deterministic, so two clients running it on
 * the same archive agree - but only while the parameters are versioned here.
 * Left implicit in the Rust, a coilbox release could change what a spot is
 * without moving `catalogVersion`, and every honest client would then look like
 * it was reporting different facts. The symptom is a flood of conflicts rather
 * than an obvious bug, which is the worst shape a bug can take.
 */
test("the metal clustering parameters are the ones agreed", () => {
  expect(catalog.metalClustering).toEqual({
    minDensityShare: 0.02,
    // Added at catalogVersion 3, when coilbox started finding spots in the
    // density map rather than reading them off a list. A share of the local
    // peak, so a broad shallow rise is not a spot however much metal it holds.
    minProminenceShare: 0.2,
    minSeparationElmos: 96,
    minSpotMetal: 0.5,
  });
});

/**
 * The caps a client has to batch to. A client that sends more than the hub
 * accepts gets a 400 it cannot do anything about, so the number it splits on
 * has to be the number the hub enforces.
 */
test("the request caps are 500 have keys, 50 submitted maps and 500 looked up names", () => {
  expect(catalog.caps.haveKeys).toBe(500);
  expect(catalog.caps.submitMaps).toBe(50);
  expect(catalog.caps.lookupNames).toBe(500);
});

/** The accessor hands the file's own numbers back rather than a copy of them, so
 * a cap moving upstream moves what the routes enforce. */
test("the caps accessor is the vendored caps", () => {
  expect(MAP_CATALOG_CAPS).toEqual(catalog.caps);
});

import { expect, test } from "bun:test";
import {
  CHANGELOG_ROW_LIMIT,
  describeUnitChange,
  modProjectChangelog,
  modProjectCounts,
} from "./modProjectPreview";

/** A payload as coilbox writes one, with only the fields the module reads. */
function payload(edits: Record<string, unknown>) {
  return { name: "A project", gameName: "Balanced Annihilation", edits };
}

test("counts read the five stores the way coilbox's own editCounts does", () => {
  const counts = modProjectCounts(
    payload({
      overrides: { armsolar: { maxdamage: 100, buildpic: "x.png" }, armmex: { metal: 5 } },
      text: { armsolar: { en: { name: "Sun Catcher" } } },
      clones: { armsolar2: { source: "armsolar", def: {} } },
      menus: { armlab: [{ op: "add", unit: "armsolar2" }] },
      disabled: ["armwar"],
    }),
  );

  expect(counts).toEqual({
    unitsTouched: 2, // armsolar and armmex both have overrides
    fields: 4, // 2 override fields on armsolar + 1 on armmex + 1 text field
    clones: 1,
    menuOps: 1,
    disabled: 1,
  });
});

test("a unit named in overrides and text counts once for unitsTouched", () => {
  const counts = modProjectCounts(
    payload({
      overrides: { armsolar: { maxdamage: 100 } },
      text: { armsolar: { en: { name: "Sun Catcher" } } },
    }),
  );

  expect(counts.unitsTouched).toBe(1);
  expect(counts.fields).toBe(2);
});

test("an empty or malformed payload counts as nothing changed, not an error", () => {
  expect(modProjectCounts({})).toEqual({
    unitsTouched: 0,
    fields: 0,
    clones: 0,
    menuOps: 0,
    disabled: 0,
  });
  expect(modProjectCounts(null)).toEqual({
    unitsTouched: 0,
    fields: 0,
    clones: 0,
    menuOps: 0,
    disabled: 0,
  });
  expect(
    modProjectCounts(
      payload({
        overrides: "not an object",
        clones: ["not an object either"],
        disabled: "not an array",
      }),
    ),
  ).toEqual({ unitsTouched: 0, fields: 0, clones: 0, menuOps: 0, disabled: 0 });
});

test("a unit with an empty patch is not counted as touched", () => {
  // Coilbox's own sparseness rule drops a unit whose last field was cleared
  // rather than leaving an empty table behind, but a hand edited or damaged
  // file might still carry one.
  const counts = modProjectCounts(payload({ overrides: { armsolar: {} } }));
  expect(counts.unitsTouched).toBe(0);
  expect(counts.fields).toBe(0);
});

test("unit keys are read trimmed and lower cased, the way every store keys itself", () => {
  const changelog = modProjectChangelog(
    payload({ overrides: { " ArmSolar ": { maxdamage: 100 } } }),
  );
  expect(changelog).toHaveLength(1);
  expect(changelog[0].unit).toBe("armsolar");
});

test("the changelog lists every unit any store names, alphabetically", () => {
  const changelog = modProjectChangelog(
    payload({
      overrides: { armwar: { maxdamage: 1 } },
      clones: { armsolar2: { source: "armsolar", def: {} } },
      disabled: ["armmex"],
    }),
  );

  expect(changelog.map((c) => c.unit)).toEqual(["armmex", "armsolar2", "armwar"]);
});

test("a unit touched by more than one store carries all of it in one row", () => {
  const changelog = modProjectChangelog(
    payload({
      overrides: { armsolar: { maxdamage: 1, buildpic: "x" } },
      menus: { armsolar: [{ op: "add", unit: "armmex" }] },
      disabled: ["armsolar"],
    }),
  );

  expect(changelog).toHaveLength(1);
  expect(changelog[0]).toEqual({
    unit: "armsolar",
    fields: 2,
    textFields: 0,
    added: false,
    source: undefined,
    disabled: true,
    menuOps: 1,
  });
});

test("describeUnitChange reads as a changelog line, joining only what changed", () => {
  expect(
    describeUnitChange({
      unit: "armsolar",
      fields: 3,
      textFields: 0,
      added: false,
      disabled: false,
      menuOps: 0,
    }),
  ).toBe("3 fields changed");

  expect(
    describeUnitChange({
      unit: "armsolar2",
      fields: 0,
      textFields: 0,
      added: true,
      source: "armsolar",
      disabled: false,
      menuOps: 0,
    }),
  ).toBe("copied from armsolar");

  expect(
    describeUnitChange({
      unit: "armsolar2",
      fields: 0,
      textFields: 0,
      added: true,
      disabled: false,
      menuOps: 0,
    }),
  ).toBe("added");

  expect(
    describeUnitChange({
      unit: "armwar",
      fields: 0,
      textFields: 0,
      added: false,
      disabled: true,
      menuOps: 0,
    }),
  ).toBe("disabled");

  expect(
    describeUnitChange({
      unit: "armlab",
      fields: 1,
      textFields: 2,
      added: true,
      source: "corlab",
      disabled: true,
      menuOps: 3,
    }),
  ).toBe(
    "1 field changed, 2 text fields changed, copied from corlab, 3 build menu edits, disabled",
  );
});

test("the row limit is a real cap the changelog itself does not apply", () => {
  // The changelog function returns every row. Capping what renders is the
  // component's job, which is what this constant bounds.
  expect(CHANGELOG_ROW_LIMIT).toBeGreaterThan(0);
  const overrides = Object.fromEntries(
    Array.from({ length: CHANGELOG_ROW_LIMIT + 50 }, (_, i) => [`unit${i}`, { field: 1 }]),
  );
  const changelog = modProjectChangelog(payload({ overrides }));
  expect(changelog).toHaveLength(CHANGELOG_ROW_LIMIT + 50);
});

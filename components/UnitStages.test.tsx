import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { StageStats, StageStrip } from "@/components/UnitStages";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import type { StageStatRow, UnitStage } from "@/lib/games/units";

/**
 * How a morph chain reads on a unit's page (#295): the levels in order, each
 * one a link to its own page, and the one being shown marked so a reader knows
 * where they are.
 */

const NO_PICTURES: ReadonlyMap<string, ResolvedAsset> = new Map();

const STAGES: UnitStage[] = [
  {
    unit_name: "armcom1",
    label: "Commander",
    current: false,
    from: null,
    conditions: {},
    unlocks: [],
    stats: { health: 3000 },
    found: true,
    removed_at: null,
  },
  {
    unit_name: "armcom2",
    label: "Commander, level 2",
    current: true,
    from: "armcom1",
    conditions: { metal: 600, time: 30 },
    unlocks: [{ name: "armvp", label: "Vehicle Plant" }],
    stats: { health: 4500 },
    found: true,
    removed_at: null,
  },
];

test("every stage links to its own page, the one being shown included", () => {
  const html = renderToStaticMarkup(
    <StageStrip game="BA" stages={STAGES} pictures={NO_PICTURES} />,
  );

  // A level keeps its own URL, which is the whole point of not redirecting it
  // to the base. The current stage stays a link so a reader can copy it.
  expect(html).toContain('href="/games/BA/units/armcom1"');
  expect(html).toContain('href="/games/BA/units/armcom2"');
  expect(html).toContain("showing this one");
});

test("what an upgrade costs prints in the game's own words", () => {
  const html = renderToStaticMarkup(
    <StageStrip game="BA" stages={STAGES} pictures={NO_PICTURES} />,
  );

  expect(html).toContain("Reached from armcom1");
  expect(html).toContain("600");
  expect(html).toContain("30");
});

test("what a level unlocks links to the thing it unlocked", () => {
  const html = renderToStaticMarkup(
    <StageStrip game="BA" stages={STAGES} pictures={NO_PICTURES} />,
  );

  expect(html).toContain("Unlocks");
  expect(html).toContain('href="/games/BA/units/armvp"');
  expect(html).toContain("Vehicle Plant");
});

test("a morph whose terms nobody reported still says where it came from", () => {
  const bare: UnitStage[] = [
    STAGES[0],
    { ...STAGES[1], conditions: {}, unlocks: [] },
  ];
  const html = renderToStaticMarkup(
    <StageStrip game="BA" stages={bare} pictures={NO_PICTURES} />,
  );

  // Silence here would read as a free upgrade, which is a claim the catalog
  // never made.
  expect(html).toContain("Reached from armcom1");
  expect(html).toContain("the extraction did not report");
});

test("a retired stage and one missing from a release say so", () => {
  const patched: UnitStage[] = [
    { ...STAGES[0], removed_at: "2026-01-01" },
    { ...STAGES[1], found: false },
  ];
  const html = renderToStaticMarkup(
    <StageStrip game="BA" stages={patched} pictures={NO_PICTURES} />,
  );

  expect(html).toContain("retired");
  expect(html).toContain("not in this release");
});

/** The stats across the stages: a column per level, so what an upgrade buys
 *  is read off one row rather than two pages. */

const ROWS: StageStatRow[] = [
  { key: "health", label: "Health", values: ["3000", "4500"], changed: true },
  { key: "footprint", label: "Footprint", values: ["4x4", "4x4"], changed: false },
];

test("the table carries a column per stage, headed by its name", () => {
  const html = renderToStaticMarkup(<StageStats stages={STAGES} rows={ROWS} />);

  expect(html).toContain("Commander, level 2");
  expect(html).toContain("3000");
  expect(html).toContain("4500");
  // Three columns to a row: the stat's name and one value per stage.
  expect(html.split("<td").length - 1).toBe(4);
});

test("a stat that holds still across the stages still prints", () => {
  const html = renderToStaticMarkup(<StageStats stages={STAGES} rows={ROWS} />);

  // Quieter than the rows that move, but present: a stat an upgrade does not
  // change is itself worth knowing.
  expect(html).toContain("4x4");
  expect(html).toContain("Footprint");
});

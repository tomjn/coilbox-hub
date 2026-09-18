import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { ItemPreview, type UnitNameLink } from "@/components/ItemPreview";

/**
 * What a blueprint's lists say about its buildings, proved by rendering them.
 * The two states are the whole feature: a def the game catalog knows, which
 * reads as its human name linked to the unit's encyclopedia page, and a def it
 * does not, which has to keep reading as the raw key exactly as it always did.
 */

/** A payload as coilbox writes one, with only the fields the preview reads. */
function payload(fields: Record<string, unknown>) {
  return { name: "A layout", buildings: [], footprints: {}, ...fields };
}

const SOLAR = "/games/byar/units/armsolar";

/** The catalog knows armsolar under its authored spelling and full name. */
function names(extra: Record<string, UnitNameLink> = {}) {
  return new Map<string, UnitNameLink>(
    Object.entries({
      armsolar: {
        label: "Solar Collector",
        href: SOLAR,
      },
      ...extra,
    }),
  );
}

function render(container: unknown, names?: ReadonlyMap<string, UnitNameLink>) {
  return renderToStaticMarkup(
    <ItemPreview kind="blueprint" container={{ payload: container }} names={names} />,
  );
}

test("a def the catalog holds reads as its name, linked to the encyclopedia", () => {
  const html = render(
    payload({ buildings: [{ def: "armsolar", offset: { x: 0, z: 0 }, facing: 0 }] }),
    names(),
  );
  expect(html).toContain(`href="${SOLAR}"`);
  expect(html).toContain("Solar Collector");
});

test("a def the catalog does not hold keeps its raw key, unlinked", () => {
  const html = render(
    payload({ buildings: [{ def: "armmex", offset: { x: 0, z: 0 }, facing: 0 }] }),
    names(),
  );
  expect(html).toContain("armmex");
  expect(html).not.toContain('href="/games/');
});

test("the roster keys on the lower cased def, so mixed case payloads still link", () => {
  const html = render(
    payload({ buildings: [{ def: "ArmSolar", offset: { x: 0, z: 0 }, facing: 0 }] }),
    names(),
  );
  expect(html).toContain(`href="${SOLAR}"`);
});

test("a build order line is linked the same way a roster line is", () => {
  const html = render(
    payload({
      ordered: true,
      buildings: [
        { def: "armsolar", offset: { x: 0, z: 0 }, facing: 0 },
        { def: "armsolar", offset: { x: 16, z: 0 }, facing: 0 },
        { def: "armmex", offset: { x: 32, z: 0 }, facing: 0 },
      ],
    }),
    names(),
  );
  // Two runs, one per kind, each naming what was built there.
  expect(html.match(new RegExp(SOLAR, "g"))).toHaveLength(1);
  expect(html).toContain("2 ");
});

test("the plan draws in its own fixed colour, not grey (issue #318)", () => {
  const html = render(
    payload({ buildings: [{ def: "armsolar", offset: { x: 0, z: 0 }, facing: 0 }] }),
    names(),
  );
  expect(html).toContain("text-blue-500");
});

/** A conquest challenge container, with the names a galaxy resolved to when
 *  the payload carries them. */
function challenge(extra: Record<string, unknown>) {
  return {
    payload: {
      mode: "conquest",
      settings: {
        seed: 12345,
        game: { shortname: "ba" },
        title: "A Conquest",
        nodeCount: 8,
        factionCount: 1,
        layout: "scatter",
        skin: "galaxy",
        ...extra,
      },
    },
  };
}

const NODE_NAMES = Object.fromEntries(
  Array.from({ length: 8 }, (_, i) => [`node-${i}`, `Star ${i}`]),
);

test("a galaxy names its systems and its factions from the payload", () => {
  const html = renderToStaticMarkup(
    <ItemPreview
      kind="challenge"
      container={challenge({
        nodeNames: NODE_NAMES,
        nodeMaps: { "node-0": "Comet Catcher" },
        factions: [
          { name: "Arm", color: "#22aa44" },
          { name: "Cortex" },
        ],
      })}
    />,
  );

  for (let i = 0; i < 8; i++) expect(html).toContain(`Star ${i}`);
  expect(html).toContain("Comet Catcher");
  expect(html).toContain("Arm");
  expect(html).toContain("Cortex");
  // The payload's colour is what the player faction is drawn in.
  expect(html).toContain("#22aa44");
});

test("a galaxy with no names in its payload still draws (issue #397)", () => {
  // Every conquest shared before coilbox published names, so this is the case
  // the change must not break rather than an edge of it.
  const html = renderToStaticMarkup(
    <ItemPreview kind="challenge" container={challenge({})} />,
  );

  expect(html).toContain("<svg");
  expect(html).toContain("8 systems joined by");
  expect(html).not.toContain("<text");
  // No legend either: swatches with nothing beside them name nobody.
  expect(html).not.toContain("<ul");
});

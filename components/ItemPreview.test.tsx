import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import {
  ItemPreview,
  placeLabels,
  placeReveal,
  type UnitNameLink,
} from "@/components/ItemPreview";

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

/**
 * Three systems close enough together that not every name fits both above
 * and below its own star (issue #403).
 *
 * Alpha is the capital, so it goes first and keeps the spot every name used
 * to take: below its star. Beta collides with Alpha there and moves above.
 * Gamma collides with both Alpha's spot and Beta's new one, so it is left
 * off the drawing rather than printed over either.
 */
test("a name that would land on one already placed moves above, or drops if that collides too", () => {
  const systems = [
    {
      x: 20,
      y: 20,
      radius: 2.1,
      anchor: "middle" as const,
      capital: true,
      name: "Alpha",
    },
    {
      x: 20.3,
      y: 20,
      radius: 1.2,
      anchor: "middle" as const,
      capital: false,
      name: "Beta",
    },
    {
      x: 19.7,
      y: 20,
      radius: 1.2,
      anchor: "middle" as const,
      capital: false,
      name: "Gamma",
    },
  ];

  expect(placeLabels(systems)).toEqual(["below", "above", null]);
});

/**
 * A capital's revealed placement is worked out against a fixed set of
 * capital boxes, not a running one, because only one system is ever pointed
 * at at a time (issue #409). Capital sits below its own star, so a system
 * pointed at right next to it has to move above, exactly as a colliding
 * second capital would have under the old rule.
 */
test("a revealed name avoids a capital's already-drawn box, moving above if below collides", () => {
  const capitalBox = {
    left: 18,
    right: 22,
    top: 22,
    bottom: 26,
  };
  const pointedAt = {
    x: 20.3,
    y: 20,
    radius: 1.2,
    anchor: "middle" as const,
    name: "Beta",
  };

  expect(placeReveal(pointedAt, [capitalBox])).toBe("above");
});

test("a revealed name that collides with a capital both above and below has nowhere to draw", () => {
  const above = { left: 18, right: 22, top: 14, bottom: 18 };
  const below = { left: 18, right: 22, top: 22, bottom: 26 };
  const pointedAt = {
    x: 20,
    y: 20,
    radius: 1.2,
    anchor: "middle" as const,
    name: "Gamma",
  };

  expect(placeReveal(pointedAt, [above, below])).toBeNull();
});

/**
 * A shared conquest galaxy names only its capitals by default, whatever its
 * size (issue #409). `factionCount: 3` on an 80 system galaxy makes 4
 * capitals: the player and 3 enemies.
 */
test("an 80 system galaxy draws only its capitals' names, and keeps every name in its tooltip", () => {
  const names = Object.fromEntries(
    Array.from({ length: 80 }, (_, i) => [`node-${i}`, `System ${i}`]),
  );
  const html = renderToStaticMarkup(
    <ItemPreview
      kind="challenge"
      container={challenge({
        nodeCount: 80,
        factionCount: 3,
        layout: "spiral",
        nodeNames: names,
      })}
    />,
  );

  // Every name is still read out by its star's tooltip, capital or not.
  for (let i = 0; i < 80; i++) expect(html).toContain(`System ${i}`);
  // Only the 2 to 4 capitals draw their name on the galaxy itself.
  const drawn = html.match(/<text[^>]*>System \d+<\/text>/g) ?? [];
  expect(drawn.length).toBeGreaterThan(0);
  expect(drawn.length).toBeLessThanOrEqual(4);
});

/**
 * The same rule at the other end: an 8 system galaxy has room to print every
 * name below its star with nothing colliding, but this draws only its
 * capitals anyway. One rule everywhere is simpler to explain than a second
 * rule that only exists for the room a small galaxy happens to have (issue
 * #409).
 */
test("an 8 system galaxy names only its capitals too, even though every name would fit", () => {
  const html = renderToStaticMarkup(
    <ItemPreview
      kind="challenge"
      container={challenge({ nodeNames: NODE_NAMES, factionCount: 1 })}
    />,
  );

  const drawn = html.match(/<text[^>]*>Star \d+<\/text>/g) ?? [];
  // factionCount: 1 makes 2 capitals: the player and its one enemy.
  expect(drawn.length).toBe(2);
  for (let i = 0; i < 8; i++) expect(html).toContain(`Star ${i}`);
});

/**
 * Every named star is reachable from the keyboard, not only the ones whose
 * name is already on the drawing (issue #409): focus is what a revealed
 * name answers to along with hover and touch, and focus needs somewhere to
 * land in the first place.
 */
test("every named system is a keyboard focus stop, not only its capitals", () => {
  const html = renderToStaticMarkup(
    <ItemPreview
      kind="challenge"
      container={challenge({ nodeNames: NODE_NAMES, factionCount: 1 })}
    />,
  );

  expect(html.match(/tabindex="0"/g)?.length).toBe(8);
});

test("a system with no name is not a focus stop, and unlabelled cards are never interactive", () => {
  const html = renderToStaticMarkup(
    <ItemPreview kind="challenge" container={challenge({})} />,
  );

  expect(html).not.toContain('tabindex="0"');
});

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

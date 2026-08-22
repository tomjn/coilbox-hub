import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { FactionToggles, type FactionToggleOption } from "@/components/FactionToggles";

/**
 * The active side reads as pressed and is not a link to itself; every other
 * side is (#269).
 */

const OPTIONS: FactionToggleOption[] = [
  { key: "", label: "All", href: "/games/BA/units", active: false },
  { key: "arm", label: "Arm", href: "/games/BA/units?faction=arm", active: true },
  { key: "core", label: "Core", href: "/games/BA/units?faction=core", active: false },
];

test("the active side is a pressed span, the rest are links", () => {
  const html = renderToStaticMarkup(<FactionToggles options={OPTIONS} />);

  expect(html).toContain('aria-current="true"');
  expect(html).toContain(">Arm</span>");
  expect(html).not.toContain('href="/games/BA/units?faction=arm"');
  expect(html).toContain('href="/games/BA/units?faction=core"');
});

test("the group is announced as one control", () => {
  const html = renderToStaticMarkup(<FactionToggles options={OPTIONS} />);

  expect(html).toContain('role="group"');
  expect(html).toContain('aria-label="Faction"');
});

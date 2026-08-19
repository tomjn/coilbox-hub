import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import {
  ModerationCrumb,
  type ModerationSection,
  ModerationNav,
} from "@/components/ModerationNav";

const DESTINATIONS = [
  "/moderation",
  "/moderation/assets",
  "/moderation/maps",
  "/moderation/authors",
  "/ops",
];

const SECTIONS: ModerationSection[] = [
  "reports",
  "pictures",
  "maps",
  "authors",
  "allowances",
];

/**
 * The bug this component exists for. Every page used to write its own two or
 * three links, so which pages a moderator could see depended on which page they
 * happened to be standing on.
 */
test.each(SECTIONS)("the bar on %s offers every section", (current) => {
  const html = renderToStaticMarkup(<ModerationNav current={current} />);

  for (const href of DESTINATIONS) {
    expect(html).toContain(`href="${href}"`);
  }
});

/** Colour alone would say nothing to a screen reader. */
test("only the current section is marked as the page you are on", () => {
  const html = renderToStaticMarkup(<ModerationNav current="maps" />);

  expect(html).toContain('aria-current="page"');
  expect(html.match(/aria-current/g)).toHaveLength(1);
  // The marked link is the one for the section the page belongs to.
  expect(html).toMatch(/aria-current="page"[^>]*href="\/moderation\/maps"/);
});

/**
 * Both states have to set the border colour. Tailwind resolves two colours on
 * one element by stylesheet order rather than by the order they are written, so
 * a shared `border-transparent` under the current link's colour would leave the
 * underline to chance.
 */
test("a link sets exactly one border colour", () => {
  const html = renderToStaticMarkup(<ModerationNav current="maps" />);

  const links = (html.match(/class="[^"]*"/g) ?? []).filter((classes) =>
    classes.includes("border-b-2"),
  );

  expect(links).toHaveLength(5);
  for (const link of links) {
    expect(link.match(/border-(transparent|neutral-500)/g)).toHaveLength(1);
  }
});

/**
 * A sub-page keeps its section lit rather than adding itself to the bar, so the
 * crumb is the only thing saying which section it belongs to.
 */
test("a sub-page crumb links up to its section", () => {
  const html = renderToStaticMarkup(
    <ModerationCrumb parent="pictures">Trail</ModerationCrumb>,
  );

  expect(html).toContain('href="/moderation/assets"');
  expect(html).toContain("Pictures");
  expect(html).toContain("Trail");
});

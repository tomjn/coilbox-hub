import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { MapMirrors } from "@/components/MapMirrors";

const HAKORA = {
  name: "hakora",
  url: "http://hakora.xyz/files/springrts/maps/comet_catcher_remake_1.8.sd7",
};

/**
 * A host can be turned off at any time and a map can have no filename to build a
 * URL from, so no links is an ordinary state rather than a fault. A heading over
 * an empty list would put the hub's own configuration on the reader's page.
 */
test("a map with nowhere to send the reader renders no section at all", () => {
  expect(renderToStaticMarkup(<MapMirrors links={[]} />)).toBe("");
});

/**
 * Nothing has asked hakora whether it holds this map, so the words have to
 * survive being wrong. A page promising a download is a page that lies on every
 * map that host does not have.
 */
test("the link says to look for the map rather than promising a download", () => {
  const html = renderToStaticMarkup(<MapMirrors links={[HAKORA]} />);

  expect(html).toContain("Look for it on hakora");
  expect(html).toContain(HAKORA.url);
  expect(html.toLowerCase()).not.toContain("download from");
});

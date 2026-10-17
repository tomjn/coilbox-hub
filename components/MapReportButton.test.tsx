import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { MapReportButton, MapReportForm } from "@/components/MapReportButton";
import { MAP_REPORT_KINDS } from "@/lib/maps/report";

const MAP_ID = "0f8fad5b-0294-4000-8000-000000000001";

test("the control starts as a plain button and shows no form", () => {
  const html = renderToStaticMarkup(<MapReportButton mapId={MAP_ID} />);

  expect(html).toContain("Report a problem with this map");
  expect(html).not.toContain("<form");
});

test("the form names the map and offers every kind as a required choice", () => {
  const html = renderToStaticMarkup(<MapReportForm mapId={MAP_ID} onCancel={() => {}} />);

  expect(html).toContain(`<input type="hidden" name="map_id" value="${MAP_ID}"/>`);
  for (const kind of MAP_REPORT_KINDS) {
    expect(html).toContain(`value="${kind.value}"`);
    expect(html).toContain(kind.label);
  }
  expect(html.match(/type="radio"[^>]*required/g)?.length).toBe(MAP_REPORT_KINDS.length);
  expect(html).toContain("<textarea");
  expect(html).toContain("Send");
  expect(html).not.toContain('role="alert"');
});

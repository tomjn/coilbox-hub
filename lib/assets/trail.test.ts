import { expect, test } from "bun:test";
import { ASSET_EVENT_ACTIONS } from "./asset";
import { eventLine, type TrailEvent } from "./trail";

function event(over: Partial<TrailEvent>): TrailEvent {
  return {
    id: 1,
    action: "approved",
    rejectionKind: null,
    actor: null,
    uploader: null,
    at: "2026-08-14T09:00:00Z",
    assetId: "0f8fad5b-d9cb-469f-a165-70867728950e",
    name: "armsolar",
    detail: "bar buildpic",
    ...over,
  };
}

/**
 * The distinction the whole audit trail exists for. A moderator reading the log
 * a year later has to be able to tell "we took down illegal content" from "we
 * took down a bad upload" without opening anything.
 */
test("the two rejections do not read alike", () => {
  const safety = eventLine(event({ action: "rejected", rejectionKind: "safety" }));
  const editorial = eventLine(event({ action: "rejected", rejectionKind: "editorial" }));

  expect(safety).toContain("safety");
  expect(safety).toContain("final");
  expect(editorial).toContain("editorial");
  expect(editorial).not.toContain("safety");
});

test("a rejection recorded before there were kinds says so rather than guessing", () => {
  expect(eventLine(event({ action: "rejected", rejectionKind: "unrecorded" }))).toBe(
    "Rejected as unrecorded",
  );
});

/** The two trusted paths stay apart, for the reason #101 splits the two
 * capabilities behind them. */
test("seeding and waiving the queue are different lines", () => {
  expect(eventLine(event({ action: "seeded" }))).not.toBe(eventLine(event({ action: "bypassed" })));
});

test("every action the database can record has something to say", () => {
  for (const action of ASSET_EVENT_ACTIONS) {
    const kind = action === "rejected" ? "editorial" : null;
    expect(eventLine(event({ action, rejectionKind: kind }))).not.toBe("");
  }
});

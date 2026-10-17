import { expect, test } from "bun:test";
import { ASSET_EVENT_ACTIONS } from "./asset";
import { eventLine, type TrailEvent, uploaderNameLine } from "./trail";

function event(over: Partial<TrailEvent>): TrailEvent {
  return {
    id: 1,
    action: "approved",
    rejectionKind: null,
    actor: null,
    uploader: null,
    uploaderName: null,
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

/** #138. A trusted account replacing somebody else's picture has to read as
 * exactly that, not as an ordinary return to the queue. */
test("a takeover says it was another account's picture, replaced on a capability", () => {
  const line = eventLine(event({ action: "replaced" }));

  expect(line).toContain("another account");
  expect(line).toContain("capability");
  expect(line).not.toBe(eventLine(event({ action: "returned" })));
});

test("every action the database can record has something to say", () => {
  for (const action of ASSET_EVENT_ACTIONS) {
    const kind = action === "rejected" ? "editorial" : null;
    expect(eventLine(event({ action, rejectionKind: kind }))).not.toBe("");
  }
});

/** #146. A safety rejection keeps who uploaded the picture, and the trail has to
 * say so, and say when the account behind it has gone. */
test("a safety rejection names the uploader by both names", () => {
  const line = uploaderNameLine(
    event({
      action: "rejected",
      rejectionKind: "safety",
      uploaderName: { displayName: "Zyx Hub Name", providerName: "zyx_handle", accountClosed: false },
    }),
  );

  expect(line).toContain("Zyx Hub Name");
  expect(line).toContain("zyx_handle");
  expect(line).not.toContain("closed");
});

test("the names say they were kept once the account has closed", () => {
  const line = uploaderNameLine(
    event({
      action: "rejected",
      rejectionKind: "safety",
      uploaderName: { displayName: "Zyx Hub Name", providerName: null, accountClosed: true },
    }),
  );

  expect(line).toContain("Zyx Hub Name");
  expect(line).toContain("kept after the account closed");
});

test("an event with no names kept says nothing about them", () => {
  expect(uploaderNameLine(event({ action: "rejected", rejectionKind: "editorial" }))).toBeNull();
  expect(
    uploaderNameLine(
      event({
        action: "rejected",
        rejectionKind: "safety",
        uploaderName: { displayName: null, providerName: null, accountClosed: true },
      }),
    ),
  ).toBeNull();
});

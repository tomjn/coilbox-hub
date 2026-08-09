import { expect, test } from "bun:test";
import { encodeContainerCode, SUPPORTED_KIND_VERSIONS } from "@/lib/container";
import { accept } from "./publish";

const presetPayload = {
  gameName: "Beyond All Reason",
  mapName: "Comet Catcher Remake",
  startPosType: 2,
  modOptionValues: {},
  participants: [],
};

function presetCode(version = SUPPORTED_KIND_VERSIONS.preset) {
  return encodeContainerCode("preset", version, presetPayload);
}

test("a preset code is accepted and describes itself", () => {
  const result = accept(presetCode());

  expect(result.ok).toBe(true);
  if (!result.ok) return;
  expect(result.accepted.kind).toBe("preset");
  expect(result.accepted.gameName).toBe("Beyond All Reason");
  expect(result.accepted.mapName).toBe("Comet Catcher Remake");
});

test("surrounding whitespace from a copy and paste is tolerated", () => {
  expect(accept(`\n  ${presetCode()}  \n`).ok).toBe(true);
});

test("a coilbox share link is accepted, since that is what Share copies", () => {
  const link = `coilbox://import?code=${encodeURIComponent(presetCode())}`;

  const result = accept(link);
  expect(result.ok).toBe(true);
  if (!result.ok) return;
  expect(result.accepted.kind).toBe("preset");
  expect(result.accepted.mapName).toBe("Comet Catcher Remake");
});

test("a link pointing at a remote file says so, rather than reading as junk", () => {
  const result = accept("coilbox://import?url=https://example.com/thing.json");
  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.reason).toContain("hosted somewhere else");
});

test("a join link is told apart from something publishable", () => {
  const result = accept("coilbox://join?server=example.com&battle=7");
  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.reason).toContain("does not carry anything to publish");
});

test("the JSON contents of an exported file are accepted", () => {
  const json = JSON.stringify({
    format: "coilbox",
    container: 1,
    kind: "preset",
    kindVersion: SUPPORTED_KIND_VERSIONS.preset,
    payload: presetPayload,
  });

  expect(accept(json).ok).toBe(true);
});

test("nothing pasted is a prompt, not an error", () => {
  const result = accept("   ");
  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.reason).toContain("Paste a share link or code");
});

test("something that is not from coilbox is turned away", () => {
  const result = accept("https://example.com/not-a-code");
  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.reason).toContain("not something coilbox made");
});

test("a container from a newer coilbox is refused rather than half read", () => {
  const result = accept(presetCode(SUPPORTED_KIND_VERSIONS.preset + 1));
  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.reason).toContain("newer coilbox");
});

test("a campaign is refused, because the gallery does not carry them", () => {
  const code = encodeContainerCode(
    "campaign",
    SUPPORTED_KIND_VERSIONS.campaign,
    { campaign: { id: "c", name: "Test", missions: [] }, media: {} },
  );

  const result = accept(code);
  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.reason).toContain("does not carry");
});

test("a scenario is accepted, with no derived names yet", () => {
  const code = encodeContainerCode(
    "scenario",
    SUPPORTED_KIND_VERSIONS.scenario,
    { triggers: [], zones: [] },
  );

  const result = accept(code);
  expect(result.ok).toBe(true);
  if (!result.ok) return;
  expect(result.accepted.kind).toBe("scenario");
  expect(result.accepted.mapName).toBeNull();
});

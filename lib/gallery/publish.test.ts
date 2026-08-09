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

test("nothing pasted is a prompt, not an error", () => {
  const result = accept("   ");
  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.reason).toContain("Paste a share code");
});

test("something that is not a share code is turned away", () => {
  const result = accept("https://example.com/not-a-code");
  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.reason).toContain("not a coilbox share code");
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

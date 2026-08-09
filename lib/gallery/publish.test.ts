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

// Shape taken from a real published pack, not invented.
function packCode(maps: string[]) {
  return encodeContainerCode(
    "setup-pack",
    SUPPORTED_KIND_VERSIONS["setup-pack"],
    { game: { name: "SplinterFaction 0.1.78" }, engineVersion: ".spring", maps },
  );
}

test("a setup pack names its game", () => {
  const result = accept(packCode(["All That Simmers v1.1.1"]));
  expect(result.ok).toBe(true);
  if (!result.ok) return;
  expect(result.accepted.gameName).toBe("SplinterFaction 0.1.78");
  expect(result.accepted.mapName).toBe("All That Simmers v1.1.1");
});

test("a pack carrying several maps claims none of them", () => {
  const result = accept(packCode(["One", "Two"]));
  expect(result.ok).toBe(true);
  if (!result.ok) return;
  expect(result.accepted.gameName).toBe("SplinterFaction 0.1.78");
  expect(result.accepted.mapName).toBeNull();
});

// Shape taken from a real published conquest run.
test("a challenge names its game by shortname", () => {
  const code = encodeContainerCode(
    "challenge",
    SUPPORTED_KIND_VERSIONS.challenge,
    {
      mode: "conquest",
      settings: {
        game: { shortname: "BA" },
        nodeCount: 12,
        seed: 7,
        title: "BA Conquest Test Run",
      },
    },
  );

  const result = accept(code);
  expect(result.ok).toBe(true);
  if (!result.ok) return;
  expect(result.accepted.gameName).toBe("BA");
  expect(result.accepted.mapName).toBeNull();
});

test("a shortname wins over a pinned build name when both are present", () => {
  // The common shape for a pinned challenge: a stable shortname alongside the
  // exact build it was set up on. The shortname is what groups it with other
  // challenges for the same game across builds, so it is what the row shows.
  const code = encodeContainerCode(
    "challenge",
    SUPPORTED_KIND_VERSIONS.challenge,
    { mode: "warpath", settings: { game: { pinnedName: "Full Name 1.2.3", shortname: "FN" } } },
  );

  const result = accept(code);
  expect(result.ok).toBe(true);
  if (!result.ok) return;
  expect(result.accepted.gameName).toBe("FN");
});

test("a preset carrying the unified game field resolves its game through it", () => {
  const code = encodeContainerCode("preset", SUPPORTED_KIND_VERSIONS.preset, {
    game: { name: "Beyond All Reason 1.2.3", shortname: "BAR" },
    mapName: "Comet Catcher Remake",
    startPosType: 2,
    modOptionValues: {},
    participants: [],
  });

  const result = accept(code);
  expect(result.ok).toBe(true);
  if (!result.ok) return;
  expect(result.accepted.gameName).toBe("BAR");
});

test("a scenario names its game, the same as any other kind", () => {
  // Shape per gameIdentity.ts: a scenario export wraps the document (which
  // carries setup.gameName) beside its dialogue media.
  const code = encodeContainerCode("scenario", SUPPORTED_KIND_VERSIONS.scenario, {
    scenario: {
      triggers: [],
      zones: [],
      setup: { gameName: "Beyond All Reason" },
    },
    media: {},
  });

  const result = accept(code);
  expect(result.ok).toBe(true);
  if (!result.ok) return;
  expect(result.accepted.kind).toBe("scenario");
  expect(result.accepted.gameName).toBe("Beyond All Reason");
  expect(result.accepted.mapName).toBeNull();
});

test("a scenario with nothing to name its game yields no game name", () => {
  const code = encodeContainerCode(
    "scenario",
    SUPPORTED_KIND_VERSIONS.scenario,
    { triggers: [], zones: [] },
  );

  const result = accept(code);
  expect(result.ok).toBe(true);
  if (!result.ok) return;
  expect(result.accepted.kind).toBe("scenario");
  expect(result.accepted.gameName).toBeNull();
  expect(result.accepted.mapName).toBeNull();
});

import { expect, test } from "bun:test";
import {
  participantColorCss,
  participantLabel,
  participantSideLabel,
  presetComposition,
} from "./presetPreview";

test("colour is read as a coilbox Rgb tuple, not an {r,g,b} object", () => {
  // Real preset payloads (published from a real coilbox export) carry
  // `color` as a 3-element array of floats 0..1, per `Rgb` in
  // `src/play/participants.ts`. Reading it as an object produced black for
  // every participant until this was caught by publishing a real preset.
  expect(participantColorCss([0.9, 0.24, 0.2])).toBe("rgb(230 61 51)");
  expect(participantColorCss([0, 0, 0])).toBe("rgb(0 0 0)");
  expect(participantColorCss([1, 1, 1])).toBe("rgb(255 255 255)");
});

test("a missing or malformed colour degrades to black rather than throwing", () => {
  expect(participantColorCss(undefined)).toBe("rgb(0 0 0)");
  expect(participantColorCss([] as unknown as [number, number, number])).toBe(
    "rgb(0 0 0)",
  );
});

test("out-of-range colour components clamp instead of producing invalid CSS", () => {
  expect(participantColorCss([-1, 2, 0.5])).toBe("rgb(0 255 128)");
});

test("the you row is named for the player, falling back to 'You'", () => {
  expect(participantLabel({ kind: "you", name: "Tom" })).toBe("Tom");
  expect(participantLabel({ kind: "you" })).toBe("You");
});

test("an ai row prefers the ai's own name, then its short name, then the row name", () => {
  expect(
    participantLabel({ kind: "ai", ai: { name: "BARbarian", shortName: "BARb" } }),
  ).toBe("BARbarian");
  expect(participantLabel({ kind: "ai", ai: { shortName: "BARb" } })).toBe("BARb");
  expect(participantLabel({ kind: "ai", name: "AI 1" })).toBe("AI 1");
});

test("an ai row with no ai reference and no name is an open slot", () => {
  expect(participantLabel({ kind: "ai" })).toBe("Open slot");
});

test("the random-side sentinel reads as 'Random', not the raw coilbox string", () => {
  // `__random__` is coilbox's RANDOM_SIDE sentinel (src/play/participants.ts)
  // for "roll a concrete side at launch". A published preset with an AI on
  // its default side showed this raw until it was translated here.
  expect(participantSideLabel("__random__")).toBe("Random");
});

test("a named side passes through, an empty or absent side shows nothing", () => {
  expect(participantSideLabel("Cortex")).toBe("Cortex");
  expect(participantSideLabel("")).toBeNull();
  expect(participantSideLabel(undefined)).toBeNull();
});

test("participants group into ally teams, ordered by ally team number", () => {
  const composition = presetComposition({
    participants: [
      { kind: "you", allyTeam: 1, color: [1, 0, 0] },
      { kind: "ai", allyTeam: 0, color: [0, 1, 0] },
      { kind: "ai", allyTeam: 1, color: [0, 0, 1] },
    ],
  });

  expect(composition).not.toBeNull();
  expect(composition?.teams.map((t) => t.allyTeam)).toEqual([0, 1]);
  expect(composition?.teams[0].members).toHaveLength(1);
  expect(composition?.teams[1].members).toHaveLength(2);
  expect(composition?.playingCount).toBe(3);
});

test("a participant with no ally team is grouped as team 0", () => {
  const composition = presetComposition({
    participants: [{ kind: "you" }],
  });
  expect(composition?.teams).toEqual([
    { allyTeam: 0, members: [{ kind: "you" }] },
  ]);
});

test("spectators are dropped from the count and the teams", () => {
  const composition = presetComposition({
    participants: [
      { kind: "you", allyTeam: 0, spectator: true },
      { kind: "ai", allyTeam: 1, spectator: false },
    ],
  });
  expect(composition?.playingCount).toBe(1);
  expect(composition?.teams).toEqual([
    { allyTeam: 1, members: [{ kind: "ai", allyTeam: 1, spectator: false }] },
  ]);
});

test("a spectator-only preset has no composition to show", () => {
  expect(
    presetComposition({
      participants: [{ kind: "you", spectator: true }],
    }),
  ).toBeNull();
});

test("a payload with no participants array degrades to no composition", () => {
  expect(presetComposition({})).toBeNull();
  expect(presetComposition({ participants: "not an array" })).toBeNull();
  expect(presetComposition({ participants: [] })).toBeNull();
});

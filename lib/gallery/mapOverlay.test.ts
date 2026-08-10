import { expect, test } from "bun:test";
import type { BarMap } from "@/lib/bar/maps";
import testMaps from "@/lib/bar/testMaps.json";
import { mapOverlay } from "./mapOverlay";

const maps = testMaps as BarMap[];
const glitters = maps.find(
  (m) => m.springName === "All That Glitters v2.2.3",
) as BarMap;

const participant = (allyTeam: number, color: [number, number, number]) => ({
  kind: "ai",
  allyTeam,
  color,
});

const preset = (payload: Record<string, unknown>) => ({
  payload: { mapName: glitters.springName, startPosType: 2, ...payload },
});

test("a two team preset gets boxes in the teams' own colours", () => {
  const { layout, allyColors, note } = mapOverlay(
    "preset",
    preset({
      participants: [participant(0, [1, 0, 0]), participant(1, [0, 0, 1])],
    }),
    glitters,
  );

  expect(layout.boxes).toHaveLength(2);
  expect(allyColors).toEqual(["rgb(255 0 0)", "rgb(0 0 255)"]);
  expect(note).toBe("Players choose in game");
});

test("spectators do not count towards the team shape", () => {
  const { layout } = mapOverlay(
    "preset",
    preset({
      participants: [
        participant(0, [1, 0, 0]),
        participant(1, [0, 0, 1]),
        { kind: "you", allyTeam: 2, spectator: true },
      ],
    }),
    glitters,
  );
  expect(layout.boxes).toHaveLength(2);
});

test("a kind with no composition gets the picture and nothing else", () => {
  const bare = mapOverlay("setup-pack", { payload: { maps: ["x"] } }, glitters);
  expect(bare).toEqual({
    layout: { boxes: [], dots: [] },
    allyColors: [],
    note: null,
  });
});

test("a spectator only preset has no shape to draw", () => {
  const { layout, allyColors } = mapOverlay(
    "preset",
    preset({ participants: [{ kind: "you", spectator: true }] }),
    glitters,
  );
  expect(layout.boxes).toEqual([]);
  expect(allyColors).toEqual([]);
});

test("a container with no payload is handled rather than thrown on", () => {
  expect(mapOverlay("preset", null, glitters).note).toBeNull();
  expect(mapOverlay("preset", {}, glitters).note).toBeNull();
});

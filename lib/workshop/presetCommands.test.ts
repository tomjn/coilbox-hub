import { expect, test } from "bun:test";
import { presetCommands } from "./presetCommands";

/** The smallest thing the container's own sniff test calls a preset. */
function preset(over: Record<string, unknown> = {}) {
  return {
    participants: [],
    gameName: "Beyond All Reason test-1234",
    mapName: "Supreme Isthmus V2",
    startPosType: 2,
    modOptionValues: {},
    ...over,
  };
}

function ai(over: Record<string, unknown> = {}) {
  return {
    id: "a",
    kind: "ai",
    name: "Barbarian",
    ai: { shortName: "BARb", kind: "native" },
    side: "Armada",
    color: [1, 0, 0],
    allyTeam: 1,
    spectator: false,
    ...over,
  };
}

test("the map comes first, because every later line resolves against it", () => {
  const commands = presetCommands(preset());
  expect(commands?.lines[0]).toBe("!map Supreme Isthmus V2");
});

test("the start position type is set before anything that depends on it", () => {
  const commands = presetCommands(preset({ startPosType: 1 }));
  expect(commands?.lines).toContain("!bSet startPosType 1");
  expect(commands?.lines.indexOf("!bSet startPosType 1")).toBe(1);
});

test("a start position type the engine does not define is left out", () => {
  const commands = presetCommands(preset({ startPosType: 7 }));
  expect(commands?.lines.some((line) => line.startsWith("!bSet startPosType"))).toBe(false);
});

test("every changed mod option becomes its own bSet line", () => {
  const commands = presetCommands(
    preset({ modOptionValues: { maxunits: "1000", deathmode: "killall" } }),
  );
  expect(commands?.lines).toContain("!bSet maxunits 1000");
  expect(commands?.lines).toContain("!bSet deathmode killall");
});

test("start boxes carry the same 0 to 200 grid, on SPADS's 1 based team number", () => {
  const commands = presetCommands(
    preset({ startRects: { "0": { left: 0, top: 0, right: 50, bottom: 200 } } }),
  );
  expect(commands?.lines).toContain("!addBox 0 0 50 200 1");
});

test("no boxes unless the engine is being told players choose in game", () => {
  const commands = presetCommands(
    preset({
      startPosType: 0,
      startRects: { "0": { left: 0, top: 0, right: 50, bottom: 200 } },
    }),
  );
  expect(commands?.lines.some((line) => line.startsWith("!addBox"))).toBe(false);
});

test("an AI becomes a bot placed on its ally team, counting from one", () => {
  const commands = presetCommands(preset({ participants: [ai()] }));
  expect(commands?.lines).toContain("!addBot Barbarian Armada BARb");
  expect(commands?.lines).toContain("!force %Barbarian team 2");
});

test("balancing is turned off before a force, or the host refuses it", () => {
  const commands = presetCommands(preset({ participants: [ai()] }));
  const lines = commands?.lines ?? [];
  expect(lines).toContain("!set autoBalance off");
  expect(lines.indexOf("!set autoBalance off")).toBeLessThan(
    lines.findIndex((line) => line.startsWith("!force")),
  );
});

test("a bot is added before it is placed", () => {
  const lines = presetCommands(preset({ participants: [ai()] }))?.lines ?? [];
  expect(lines.indexOf("!addBot Barbarian Armada BARb")).toBeLessThan(
    lines.indexOf("!force %Barbarian team 2"),
  );
});

test("a bot name a lobby will not take is rewritten, and the page says so", () => {
  const commands = presetCommands(preset({ participants: [ai({ name: "Bad Guy #1!" })] }));
  expect(commands?.lines).toContain("!addBot BadGuy1 Armada BARb");
  expect(commands?.notCarried).toContain(
    "1 bot was renamed. A lobby only takes letters, digits and brackets in a bot's name.",
  );
});

test("two bots cannot share one lobby name", () => {
  const commands = presetCommands(
    preset({ participants: [ai({ id: "a", name: "Bot" }), ai({ id: "b", name: "Bot" })] }),
  );
  expect(commands?.lines).toContain("!addBot Bot Armada BARb");
  expect(commands?.lines).toContain("!addBot Bot2 Armada BARb");
});

test("a side rolled at launch is asked for as the game's first, and said so", () => {
  const commands = presetCommands(preset({ participants: [ai({ side: "__random__" })] }));
  expect(commands?.lines).toContain("!addBot Barbarian 0 BARb");
  expect(commands?.notCarried).toContain(
    "1 bot had a random faction. A lobby cannot roll one, so they get the game's first.",
  );
});

test("no lines at all when a bot in the roster cannot be named to the host", () => {
  const commands = presetCommands(
    preset({ participants: [ai(), ai({ id: "b", ai: { shortName: "", kind: "native" } })] }),
  );
  expect(commands?.lines).toEqual([]);
  expect(commands?.withheld).toEqual([
    "One of the AI players does not say which AI it is, so the roster would be short a player.",
  ]);
});

test("the spectating human is not a bot, and neither is the one playing", () => {
  const commands = presetCommands(
    preset({
      participants: [
        { id: "you", kind: "you", name: "Tom", side: "Cortex", allyTeam: 0, spectator: false },
        ai(),
      ],
    }),
  );
  expect(commands?.lines.filter((line) => line.startsWith("!addBot"))).toHaveLength(1);
});

test("what a lobby cannot carry is named, not quietly dropped", () => {
  const commands = presetCommands(
    preset({
      participants: [
        { id: "you", kind: "you", name: "Tom", side: "Cortex", allyTeam: 0, spectator: false },
        ai(),
      ],
      restrictions: { disabledUnits: ["corak"], advantage: 0.1 },
    }),
  );
  const notCarried = commands?.notCarried ?? [];
  expect(notCarried).toContain(
    "Team colours. A lobby gives every bot the same colour and lets each person pick their own.",
  );
  expect(notCarried).toContain(
    "The faction each person plays. Only a bot's can be set from the chat.",
  );
  expect(notCarried).toContain("1 disabled unit, which no autohost command can restrict.");
  expect(notCarried).toContain(
    "A resource advantage for the first team, which is not the same as a lobby's handicap.",
  );
});

test("shared control is named rather than guessed at", () => {
  const commands = presetCommands(
    preset({ participants: [ai({ id: "a", team: 3 }), ai({ id: "b", name: "Two", team: 3 })] }),
  );
  expect(commands?.notCarried).toContain(
    "Players sharing control of one team. The host assigns those slots itself.",
  );
  expect(commands?.lines.some((line) => line.includes(" id "))).toBe(false);
});

test("a faction whose name would split into two words is not asked for", () => {
  const commands = presetCommands(preset({ participants: [ai({ side: "Legion Core" })] }));
  expect(commands?.lines).toContain("!addBot Barbarian 0 BARb");
  expect(commands?.notCarried).toContain(
    "1 bot faction cannot be named in a command, so they get the game's first.",
  );
});

test("a team's income multiplier does not carry either", () => {
  const commands = presetCommands(preset({ restrictions: { incomeMultiplier: 1.5 } }));
  expect(commands?.notCarried).toContain(
    "A resource multiplier for the first team, which a lobby has no setting for.",
  );
});

test("a start box off the grid is left out rather than sent as nonsense", () => {
  const commands = presetCommands(
    preset({
      startRects: {
        "0": { left: 0, top: 0, right: 50, bottom: 200 },
        "1": { left: 180, top: 0, right: 90, bottom: 200 },
      },
    }),
  );
  expect(commands?.lines.filter((line) => line.startsWith("!addBox"))).toEqual([
    "!addBox 0 0 50 200 1",
  ]);
  expect(commands?.notCarried).toContain(
    "1 start box the grid does not allow, so that team picks anywhere.",
  );
});

test("a preset with nothing but a map still gets the one line worth pasting", () => {
  const commands = presetCommands(preset({ startPosType: 7 }));
  expect(commands?.lines).toEqual(["!map Supreme Isthmus V2"]);
  expect(commands?.withheld).toEqual([]);
});

test("nothing is offered for a payload that is not a preset", () => {
  expect(presetCommands(null)).toBeNull();
  expect(presetCommands({ participants: [], gameName: "x" })).toBeNull();
  expect(presetCommands({ edits: { disabled: ["corak"] } })).toBeNull();
  expect(presetCommands(preset({ mapName: "" }))).toBeNull();
});

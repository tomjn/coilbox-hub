import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { LobbyCommands } from "@/components/LobbyCommands";
import { presetCommands } from "@/lib/workshop/presetCommands";
import { lobbyCommands } from "@/lib/workshop/lobbyCommands";

const PRESET = {
  participants: [
    { id: "you", kind: "you", name: "Tom", side: "Cortex", allyTeam: 0, spectator: false },
    {
      id: "a",
      kind: "ai",
      name: "Barbarian",
      ai: { shortName: "BARb", kind: "native" },
      side: "Armada",
      color: [1, 0, 0],
      allyTeam: 1,
      spectator: false,
    },
  ],
  gameName: "Beyond All Reason test-1234",
  mapName: "Comet Catcher Remake 1.8",
  startPosType: 2,
  modOptionValues: { maxunits: "1000" },
};

test("a preset's section asks for the commands in order and offers no Lua to read", () => {
  const html = renderToStaticMarkup(
    <LobbyCommands commands={presetCommands(PRESET)!} />,
  );
  expect(html).toContain("!map Comet Catcher Remake 1.8");
  expect(html).toContain("!force %Barbarian team 2");
  expect(html).toContain("in the order they are given");
  expect(html).not.toContain("Read the Lua");
});

test("a project's section still offers its Lua, and does not talk about joining", () => {
  const html = renderToStaticMarkup(
    <LobbyCommands commands={lobbyCommands({ edits: { disabled: ["corak"] } })!} />,
  );
  expect(html).toContain("Read the Lua");
  expect(html).not.toContain("in the order they are given");
});

test("a preset nobody can rebuild says so in its own words", () => {
  const commands = presetCommands({
    ...PRESET,
    participants: [{ id: "a", kind: "ai", name: "Nameless", ai: {}, allyTeam: 1 }],
  })!;
  const html = renderToStaticMarkup(<LobbyCommands commands={commands} />);
  expect(html).toContain("This preset cannot be rebuilt in a lobby");
  expect(html).toContain("does not say which AI it is");
});

import { expect, test } from "bun:test";
import { lobbyCommands } from "./lobbyCommands";

test("the project on issue #418 packs to one tweakdefs line", () => {
  const commands = lobbyCommands({
    name: "Disabled AKs in BA",
    gameName: "Balanced Annihilation V15.9.8",
    edits: { text: {}, menus: {}, clones: {}, disabled: ["corak"], overrides: {} },
  });
  expect(commands?.lines).toHaveLength(1);
  expect(commands?.lines[0]).toStartWith("!bset tweakdefs ");
  expect(commands?.withheld).toEqual([]);
  expect(commands?.chunks[0].lua).toContain('["corak"] = true');
});

test("tweakunits lines come before tweakdefs lines", () => {
  const commands = lobbyCommands({
    edits: { overrides: { armcom: { maxDamage: 1 } }, disabled: ["corak"] },
  });
  expect(commands?.lines.map((line) => line.split(" ")[1])).toEqual(["tweakunits", "tweakdefs"]);
});

test("no lines at all when part of the project would be missing from them", () => {
  const commands = lobbyCommands({
    edits: {
      clones: { big: { key: "armhuge", def: { description: "x".repeat(13000) } } },
      disabled: ["corak"],
    },
  });
  expect(commands?.lines).toEqual([]);
  expect(commands?.withheld).toEqual(['"1 unit added" is too long for one autohost command.']);
  // The Lua is still there to read.
  expect(commands?.chunks).toHaveLength(2);
});

test("two copies claiming one name withhold the lines, as coilbox's preflight does", () => {
  const commands = lobbyCommands({
    edits: {
      clones: {
        first: { key: "armdup", def: { maxDamage: 1 } },
        second: { key: "armdup", def: { maxDamage: 2 } },
      },
    },
  });
  expect(commands?.lines).toEqual([]);
  expect(commands?.withheld).toEqual(["More than one copy in this project is named armdup."]);
});

test("what a lobby cannot carry is said beside the lines", () => {
  const commands = lobbyCommands({
    edits: { disabled: ["corak"], text: { armcom: { en: { name: "A", description: "B" } } } },
    readOnlyLua: [{ title: "t", lua: "x()", note: "n" }],
  });
  expect(commands?.lines).toHaveLength(1);
  expect(commands?.notCarried).toHaveLength(2);
  expect(commands?.notCarried[0]).toStartWith("2 name or description edits.");
});

test("imported Lua the decoder proved parses is in the lines, not listed as missing", () => {
  const commands = lobbyCommands({
    edits: { disabled: ["corak"] },
    readOnlyLua: [
      { title: "tweakdefs", lua: "do local imported = 1 end", note: "n", form: "block" },
    ],
  });
  expect(commands?.notCarried).toEqual([]);
  const lua = commands?.chunks.map((chunk) => chunk.lua).join("\n");
  expect(lua).toContain("local imported = 1");
  // First, because the import is the baseline the author edited on top of.
  expect(commands?.chunks[0].title).toBe("tweakdefs");
});

test("imported Lua that never parsed is left out and said so", () => {
  const commands = lobbyCommands({
    edits: { disabled: ["corak"] },
    readOnlyLua: [{ title: "t", lua: "not lua {{{", note: "n", form: "unrecognised" }],
  });
  expect(commands?.notCarried).toEqual([
    "1 block of imported Lua that never parsed, so it is not in these lines.",
  ]);
  expect(commands?.chunks.map((chunk) => chunk.lua).join("\n")).not.toContain("not lua");
});

test("nothing is offered for a payload that is not a project, or holds no edit", () => {
  expect(lobbyCommands(null)).toBeNull();
  expect(lobbyCommands({ edits: {} })).toBeNull();
  expect(lobbyCommands({ edits: { text: { armcom: { en: { name: "A" } } } } })).toBeNull();
  expect(lobbyCommands({ edits: { disabled: "corak" } })).toBeNull();
});

test("a payload nested deep enough to run the stack out is refused, not thrown", () => {
  let def: unknown = 1;
  for (let i = 0; i < 100_000; i += 1) def = { a: def };
  expect(lobbyCommands({ edits: { clones: { a: { key: "armdeep", def } } } })).toBeNull();
});

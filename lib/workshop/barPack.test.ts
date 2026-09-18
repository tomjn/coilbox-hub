import { expect, test } from "bun:test";
import { minifyLua, packBarSlots } from "./barPack";
import { compile, TooLarge } from "./compile";
import { readModProject } from "./project";
import golden from "./vendor/bar-pack-golden.json";

// Every case is a project beside what coilbox's Rust compiler and packer made
// of it (`tests/bar_pack_golden.rs` there writes the file). A failure here
// after a vendor sync means coilbox changed what it emits, and the port in
// this directory has to follow it.
for (const entry of golden) {
  test(`packs "${entry.name}" to the lines coilbox does`, () => {
    const project = readModProject(entry.project);
    expect(project).not.toBeNull();
    const { chunks } = compile(project!);
    expect(chunks).toEqual(entry.chunks as typeof chunks);
    expect(packBarSlots(chunks)).toEqual(entry.pack);
  });
}

test("the fixture covers both slot kinds, a split and a chunk that fits nowhere", () => {
  const packs = golden.map((entry) => entry.pack);
  expect(packs.some((p) => p.tweakunits.length > 0)).toBe(true);
  expect(packs.some((p) => p.tweakdefs.length > 1)).toBe(true);
  expect(packs.some((p) => p.oversized.length > 0)).toBe(true);
});

test("a comment marker inside a string is not a comment", () => {
  expect(minifyLua('x = "a -- b"  -- gone\ny = 1')).toBe('x = "a -- b" y = 1');
});

test("a project coilbox could not parse is not read at all", () => {
  expect(readModProject({ edits: { disabled: ["corak", 5] } })).toBeNull();
  expect(readModProject({ edits: { menus: { armlab: [{ op: "swap", unit: "a" }] } } })).toBeNull();
  expect(readModProject({ edits: { clones: { a: { def: {} } } } })).toBeNull();
  expect(readModProject({ edits: null })).toBeNull();
  expect(readModProject("nope")).toBeNull();
});

test("an index no slot could hold is refused before it is allocated", () => {
  const project = readModProject({
    edits: {
      clones: { a: { key: "armbig", def: {} } },
      overrides: { armbig: { "weapons.4000000000.name": "x" } },
    },
  });
  expect(() => compile(project!)).toThrow(TooLarge);
});

test("a path step named __proto__ is a key like any other", () => {
  const project = readModProject({
    edits: {
      clones: { a: { key: "armsafe", def: {} } },
      overrides: { armsafe: { "__proto__.polluted": 1 } },
    },
  });
  compile(project!);
  expect(({} as Record<string, unknown>).polluted).toBeUndefined();
});

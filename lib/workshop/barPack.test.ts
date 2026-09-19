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

// Coilbox's own compiler writes no single-quoted or long-bracket string, but
// a project can carry Lua somebody else wrote, and the tools BAR players use
// quote with `'` throughout.
test("every Lua string form survives minifying", () => {
  expect(minifyLua("do x = 'a -- b' end")).toBe("do x = 'a -- b' end");
  expect(minifyLua("x = 'she said \"hi\"' y = 2")).toBe("x = 'she said \"hi\"' y = 2");
  expect(minifyLua("x = [[ two  spaces ]] y = 3")).toBe("x = [[ two  spaces ]] y = 3");
  expect(minifyLua("x = [==[ ]] inside ]==] y = 4")).toBe("x = [==[ ]] inside ]==] y = 4");
  expect(minifyLua("do --[[ across\nlines ]] x = 1 end")).toBe("do x = 1 end");
  expect(minifyLua("x = a[b[1]]")).toBe("x = a[b[1]]");
});

// BAR reads a tweakunits payload through `gsub(dataRaw, "_", "=")` before
// decoding, so an underscore is a byte the game loses. Its decoder reads the
// standard alphabet too, which that gsub leaves alone.
test("a tweakunits payload carries no character BAR would rewrite", () => {
  const project = readModProject({
    edits: { overrides: { corak: { name: "Танк?" } } },
  });
  const { chunks } = compile(project!);
  const payload = packBarSlots(chunks).tweakunits[0].split(" ").pop()!;
  expect(payload).not.toContain("_");
  expect(payload).toContain("/");
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

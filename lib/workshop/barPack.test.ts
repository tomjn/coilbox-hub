import { expect, test } from "bun:test";
import { packTweakSlots, minifyLua } from "./barPack";
import { compile, TooLarge } from "./compile";
import { readModProject } from "./project";
import golden from "./vendor/bar-pack-golden.json";

// Every case is a project beside what coilbox's Rust compiler and packer made
// of it (`tests/bar_pack_golden.rs` there writes the file). A failure here
// after a vendor sync means coilbox changed what it emits, and the port in
// this directory has to follow it.
//
// Coilbox's chunks also carry `unit`, the one unit a chunk is about, for its
// preflight markers (coilbox 7a49afec). The hub draws no markers, so the port
// does not emit it and the comparison leaves it out.
for (const entry of golden) {
  test(`packs "${entry.name}" to the lines coilbox does`, () => {
    const project = readModProject(entry.project);
    expect(project).not.toBeNull();
    const { chunks } = compile(project!);
    const expected = entry.chunks.map((chunk) =>
      Object.fromEntries(Object.entries(chunk).filter(([key]) => key !== "unit")),
    );
    expect(chunks).toEqual(expected as unknown as typeof chunks);
    expect(packTweakSlots(chunks)).toEqual(entry.pack);
  });
}

test("the fixture covers a table chunk, a split and a chunk that fits nowhere", () => {
  const packs = golden.map((entry) => entry.pack);
  expect(golden.some((entry) => entry.chunks.some((chunk) => chunk.form === "table"))).toBe(true);
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

// A tweakunits slot has no spelling every checked game reads back (issue
// #3126), so a table-form chunk goes to a tweakdefs slot instead, wrapped as
// a block that merges it. That slot's alphabet is URL-safe, so a value that
// would have needed a 63 reads back as `_`, never `/`.
test("a table chunk's tweakdefs payload keeps the url-safe alphabet", () => {
  const project = readModProject({
    edits: { overrides: { corak: { name: "Танк?" } } },
  });
  const { chunks } = compile(project!);
  const payload = packTweakSlots(chunks).tweakdefs[0].split(" ").pop()!;
  expect(payload).not.toContain("/");
  expect(payload).not.toContain("+");
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

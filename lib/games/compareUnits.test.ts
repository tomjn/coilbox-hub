import { expect, test } from "bun:test";
import { pickCompareRelease, resolveWithUnit } from "./compareUnits";

const units = [
  { name: "armcom", label: "Commander" },
  { name: "corcom", label: "Commander" },
  { name: "armmex", label: "Metal Extractor" },
  { name: "armlab", label: "Bot Lab" },
];

test("an id resolves to its unit", () => {
  expect(resolveWithUnit("armmex", "armcom", units)).toEqual({
    kind: "found",
    unit: { name: "armmex", label: "Metal Extractor" },
  });
});

test("an id matches whatever case it is typed in", () => {
  expect(resolveWithUnit("ARMMEX", "armcom", units)).toMatchObject({ kind: "found" });
});

test("a display name that names one unit resolves to it", () => {
  expect(resolveWithUnit("bot lab", "armcom", units)).toEqual({
    kind: "found",
    unit: { name: "armlab", label: "Bot Lab" },
  });
});

test("a display name shared by several units lists them", () => {
  const result = resolveWithUnit("Commander", "armmex", units);
  expect(result).toEqual({
    kind: "ambiguous",
    choices: [
      { name: "armcom", label: "Commander" },
      { name: "corcom", label: "Commander" },
    ],
  });
});

test("an id wins over a display name that happens to match", () => {
  const tricky = [...units, { name: "other", label: "armmex" }];
  expect(resolveWithUnit("armmex", "armcom", tricky)).toMatchObject({
    kind: "found",
    unit: { name: "armmex" },
  });
});

test("a name the game does not hold is unknown", () => {
  expect(resolveWithUnit("nope", "armcom", units)).toEqual({ kind: "unknown", asked: "nope" });
});

test("the unit itself, by id or by a name only it holds, is self", () => {
  expect(resolveWithUnit("armcom", "armcom", units)).toEqual({ kind: "self" });
  expect(resolveWithUnit("Bot Lab", "armlab", units)).toEqual({ kind: "self" });
});

test("a shared display name that includes the unit itself still offers the others", () => {
  expect(resolveWithUnit("Commander", "armcom", units)).toMatchObject({ kind: "found", unit: { name: "corcom" } });
});

test("nothing typed asks for a choice", () => {
  expect(resolveWithUnit("  ", "armcom", units)).toEqual({ kind: "none" });
  expect(resolveWithUnit(undefined, "armcom", units)).toEqual({ kind: "none" });
});

const releases = ["1.2", "1.1", "1.0"];

test("the default is the newest release holding both units", () => {
  const pick = pickCompareRelease(releases, ["1.2", "1.1", "1.0"], ["1.1", "1.0"], undefined);
  expect(pick).toMatchObject({ release: "1.1", both: ["1.1", "1.0"], missing: null });
});

test("no release holding both gives no default", () => {
  const pick = pickCompareRelease(releases, ["1.2"], ["1.0"], undefined);
  expect(pick).toMatchObject({ release: null, both: [] });
});

test("a release both hold is used as asked", () => {
  const pick = pickCompareRelease(releases, ["1.2", "1.1", "1.0"], ["1.1", "1.0"], "1.0");
  expect(pick).toMatchObject({ release: "1.0", missing: null });
});

test("a release one unit lacks says which side is missing", () => {
  const pick = pickCompareRelease(releases, ["1.2", "1.1", "1.0"], ["1.1", "1.0"], "1.2");
  expect(pick).toMatchObject({ release: null, missing: { a: false, b: true }, both: ["1.1", "1.0"] });
});

test("a release the game does not hold is flagged", () => {
  const pick = pickCompareRelease(releases, ["1.2"], ["1.2"], "9.9");
  expect(pick).toMatchObject({ release: null, unknownRelease: true, both: ["1.2"] });
});

test("an empty release is the same as none asked", () => {
  const pick = pickCompareRelease(releases, ["1.2"], ["1.2"], "");
  expect(pick).toMatchObject({ release: "1.2", unknownRelease: false });
});

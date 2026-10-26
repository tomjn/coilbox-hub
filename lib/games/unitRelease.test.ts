import { expect, test } from "bun:test";
import { readUnitSegment, unitSegment } from "./unitRelease";

test("a unit's latest facts are its name alone", () => {
  expect(unitSegment("armcom")).toBe("armcom");
  expect(readUnitSegment("armcom")).toEqual({ unit: "armcom" });
});

test("a release reads back as written, separators in either half included", () => {
  for (const [unit, release] of [
    ["building2", "test-323-1a8581e"],
    ["odd@unit", "v1@beta"],
    ["a b/c", "1.0 %rc"],
  ]) {
    expect(readUnitSegment(unitSegment(unit, release))).toEqual({ unit, release });
  }
});

test("only the separator is a literal @", () => {
  expect(unitSegment("odd@unit", "v1@beta")).toBe("odd%40unit@v1%40beta");
});

test("a segment that is not valid percent-encoding is read as it stands", () => {
  expect(readUnitSegment("bad%zz@1.0")).toEqual({ unit: "bad%zz", release: "1.0" });
});

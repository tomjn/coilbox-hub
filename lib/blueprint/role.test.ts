import { expect, test } from "bun:test";
import { buildingRole, roleCss } from "./role";

test("a builder is a builder before anything else it has", () => {
  expect(buildingRole({ buildOptions: ["armsolar"], stats: { weapons: [{}] } })).toBe("builder");
  expect(buildingRole({ stats: { builder: true, energyMake: 5 } })).toBe("builder");
});

test("a building with a weapon is a weapon before it is energy", () => {
  expect(buildingRole({ stats: { weapons: [{ range: 430 }], energyMake: 5 } })).toBe("weapon");
  expect(buildingRole({ stats: { weapons: [] } })).toBe("other");
});

test("making energy in any of the four ways the widget counts is energy", () => {
  expect(buildingRole({ stats: { energyMake: 20 } })).toBe("energy");
  expect(buildingRole({ stats: { windGenerator: 25 } })).toBe("energy");
  expect(buildingRole({ stats: { tidalGenerator: 18 } })).toBe("energy");
  // A negative upkeep is how a solar collector says it makes energy.
  expect(buildingRole({ stats: { energyUpkeep: -20 } })).toBe("energy");
  expect(buildingRole({ stats: { energyUpkeep: 20 } })).toBe("other");
});

test("a unit that declares nothing keeps the plan's own colour", () => {
  expect(buildingRole({})).toBe("other");
  expect(roleCss("other")).toBeUndefined();
});

test("the colours are the widget's fractions as hex", () => {
  expect(roleCss("builder")).toBe("#66cc66");
  expect(roleCss("weapon")).toBe("#e65447");
  expect(roleCss("energy")).toBe("#f2cc40");
});

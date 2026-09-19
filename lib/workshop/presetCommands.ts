/**
 * What a published preset offers somebody who wants to play it with other
 * people: the autohost commands that rebuild it in a multiplayer lobby.
 *
 * A preset is a skirmish snapshot, so this is a translation and not an export.
 * It has one human in it and a lobby has however many join, which means the
 * commands set up the map, the options and the bots, and the people fill the
 * rest. Anything the chat cannot say is listed rather than dropped.
 *
 * Every command here is checked against SPADS, which is what a BAR autohost
 * runs. Two of its numbers count from one where coilbox counts from zero: the
 * team on `!addBox` (`hAddBox` subtracts one before it sends the rect) and the
 * number on `!force <x> team` (`hForce` does the same). The start box grid is
 * the one place the two agree outright, both running 0,0 to 200,200.
 *
 * Unlike a mod project, a preset degrades usefully: a lobby with the right map
 * and the wrong colours is still the preset. So only the roster withholds the
 * whole list, because half a roster is a different game rather than a plainer
 * one.
 */

import type { LobbyCommands } from "./lobbyCommands";

/** Coilbox's sentinel for "roll a concrete side at launch", which a lobby has
 *  no way to ask for. Mirrored from `presetPreview.ts`, which reads it for the
 *  same reason. */
const RANDOM_SIDE = "__random__";

/** Both ends of this agree: coilbox stores boxes on it and SPADS reads it. */
const GRID = 200;

/** SPADS takes a team from 1 to 251 and subtracts one, so this is the highest
 *  ally team coilbox can hand it. */
const MAX_ALLY = 250;

/** The characters `hAddBot` will take in a bot's name, which it caps at 20. */
const BOT_NAME_CHAR = /[\w[\]]/;

/** An AI's short name, as `hAddBot` will accept it: no semicolons, and not
 *  starting with a space. */
const AI_SHORT_NAME = /^[^ ;][^;]*$/;

interface Participant {
  kind?: string;
  name?: string;
  ai?: { shortName?: string; name?: string };
  side?: string;
  allyTeam?: number;
  team?: number;
  spectator?: boolean;
}

interface Rect {
  left: number;
  top: number;
  right: number;
  bottom: number;
}

interface Preset {
  mapName: string;
  startPosType: number | null;
  modOptions: [string, string][];
  participants: Participant[];
  startRects: [number, Rect][];
  disabledUnits: number;
  advantage: boolean;
  incomeMultiplier: boolean;
}

function record(value: unknown): Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {};
}

function rect(value: unknown): Rect | null {
  const r = record(value);
  const sides = [r.left, r.top, r.right, r.bottom];
  if (!sides.every((n) => typeof n === "number" && Number.isInteger(n))) return null;
  const [left, top, right, bottom] = sides as number[];
  return { left, top, right, bottom };
}

/** `null` for anything that is not a preset coilbox wrote. The container's own
 *  sniff asks the same three questions, so this agrees with it on what a preset
 *  is and adds only that a nameless map has nothing to set. */
function readPreset(payload: unknown): Preset | null {
  const p = record(payload);
  if (!Array.isArray(p.participants)) return null;
  if (typeof p.gameName !== "string" || typeof p.mapName !== "string") return null;
  if (p.mapName === "") return null;

  const restrictions = record(p.restrictions);
  const disabled = restrictions.disabledUnits;

  return {
    mapName: p.mapName,
    // 0, 1 and 2 are every mode the engine defines. A preset written by a newer
    // coilbox against a mode this does not know says nothing rather than
    // sending a number the host would reject.
    startPosType:
      p.startPosType === 0 || p.startPosType === 1 || p.startPosType === 2
        ? p.startPosType
        : null,
    modOptions: Object.entries(record(p.modOptionValues))
      .filter(([key, value]) => key !== "" && typeof value === "string")
      .map(([key, value]): [string, string] => [key, value as string])
      .sort(([a], [b]) => a.localeCompare(b)),
    participants: p.participants as Participant[],
    startRects: Object.entries(record(p.startRects))
      .map(([ally, value]) => [Number(ally), rect(value)] as const)
      .filter((entry): entry is [number, Rect] => entry[1] !== null),
    disabledUnits: Array.isArray(disabled) ? disabled.length : 0,
    advantage: typeof restrictions.advantage === "number" && restrictions.advantage !== 0,
    incomeMultiplier:
      typeof restrictions.incomeMultiplier === "number" && restrictions.incomeMultiplier !== 1,
  };
}

function plural(n: number): string {
  return n === 1 ? "" : "s";
}

/** The label a bot is added under, made to fit what a lobby accepts and kept
 *  distinct from the bots already named. A name is cosmetic in a lobby, so this
 *  changes one rather than refusing the preset, and says how many it changed. */
function botName(label: string, taken: Set<string>): string {
  const cleaned = [...label].filter((c) => BOT_NAME_CHAR.test(c)).join("").slice(0, 20);
  const base = cleaned.length >= 2 ? cleaned : `Bot${taken.size + 1}`;

  let name = base;
  for (let n = 2; taken.has(name); n += 1) {
    const suffix = String(n);
    name = `${base.slice(0, 20 - suffix.length)}${suffix}`;
  }
  return name;
}

/**
 * The side to ask `!addBot` for. SPADS reads a digit as an index and anything
 * else as a name to match, and it splits the command on spaces, so a faction
 * whose name has one in it cannot be asked for at all. `null` means say `0`,
 * the game's first, and tell the reader that is what happened.
 */
function botSide(side: string | undefined): string | null {
  if (!side || side === RANDOM_SIDE) return null;
  return /\s/.test(side) ? null : side;
}

export function presetCommands(payload: unknown): LobbyCommands | null {
  const preset = readPreset(payload);
  if (!preset) return null;

  const lines = [`!map ${preset.mapName}`];
  if (preset.startPosType !== null) lines.push(`!bSet startPosType ${preset.startPosType}`);
  for (const [key, value] of preset.modOptions) lines.push(`!bSet ${key} ${value}`);

  // Boxes are only meaningful when the players are the ones choosing, and
  // `hAddBox` refuses them outright in any other mode.
  let badBoxes = 0;
  if (preset.startPosType === 2) {
    for (const [ally, box] of preset.startRects) {
      const onGrid = [box.left, box.top, box.right, box.bottom].every(
        (n) => n >= 0 && n <= GRID,
      );
      if (!onGrid || box.left > box.right || box.top > box.bottom) {
        badBoxes += 1;
        continue;
      }
      if (!Number.isInteger(ally) || ally < 0 || ally > MAX_ALLY) {
        badBoxes += 1;
        continue;
      }
      lines.push(`!addBox ${box.left} ${box.top} ${box.right} ${box.bottom} ${ally + 1}`);
    }
  }

  const bots = preset.participants.filter((p) => p.kind === "ai");
  const withheld: string[] = [];
  const taken = new Set<string>();
  const addBot: string[] = [];
  const force: string[] = [];
  let renamed = 0;
  let unnamedSides = 0;
  let randomSides = 0;

  for (const bot of bots) {
    const shortName = bot.ai?.shortName ?? "";
    if (shortName === "" || !AI_SHORT_NAME.test(shortName)) {
      withheld.push(
        "One of the AI players does not say which AI it is, so the roster would be short a player.",
      );
      break;
    }

    const label = bot.name || bot.ai?.name || shortName;
    const name = botName(label, taken);
    taken.add(name);
    if (name !== label) renamed += 1;

    const side = botSide(bot.side);
    if (side === null) {
      if (bot.side === RANDOM_SIDE) randomSides += 1;
      else if (bot.side) unnamedSides += 1;
    }

    addBot.push(`!addBot ${name} ${side ?? "0"} ${shortName}`);
    const ally = bot.allyTeam ?? 0;
    if (Number.isInteger(ally) && ally >= 0 && ally <= MAX_ALLY) {
      // A bot arrives on team 0 whatever it was added for, so every one of
      // them is placed and not only the ones away from the first team.
      force.push(`!force %${name} team ${ally + 1}`);
    }
  }

  if (force.length > 0) {
    // `hForce` refuses an id or a team outright while the host is balancing for
    // itself, so the roster below would land on the floor without this.
    lines.push("!set autoBalance off");
  }
  lines.push(...addBot, ...force);

  const shared = new Map<number, number>();
  for (const p of preset.participants) {
    if (typeof p.team === "number") shared.set(p.team, (shared.get(p.team) ?? 0) + 1);
  }

  const notCarried = [
    preset.participants.length > 0 &&
      "Team colours. A lobby gives every bot the same colour and lets each person pick their own.",
    preset.participants.some(
      (p) => p.kind !== "ai" && p.side && p.side !== RANDOM_SIDE,
    ) && "The faction each person plays. Only a bot's can be set from the chat.",
    randomSides > 0 &&
      `${randomSides} bot${plural(randomSides)} had a random faction. A lobby cannot roll one, so they get the game's first.`,
    unnamedSides > 0 &&
      `${unnamedSides} bot faction${plural(unnamedSides)} cannot be named in a command, so they get the game's first.`,
    renamed > 0 &&
      `${renamed} bot${plural(renamed)} ${renamed === 1 ? "was" : "were"} renamed. A lobby only takes letters, digits and brackets in a bot's name.`,
    [...shared.values()].some((n) => n > 1) &&
      "Players sharing control of one team. The host assigns those slots itself.",
    badBoxes > 0 &&
      `${badBoxes} start box${badBoxes === 1 ? "" : "es"} the grid does not allow, so that team picks anywhere.`,
    preset.disabledUnits > 0 &&
      `${preset.disabledUnits} disabled unit${plural(preset.disabledUnits)}, which no autohost command can restrict.`,
    preset.advantage &&
      "A resource advantage for the first team, which is not the same as a lobby's handicap.",
    preset.incomeMultiplier &&
      "A resource multiplier for the first team, which a lobby has no setting for.",
  ].filter((note): note is string => typeof note === "string");

  return {
    kind: "preset",
    lines: withheld.length > 0 ? [] : lines,
    withheld,
    notCarried,
    chunks: [],
  };
}

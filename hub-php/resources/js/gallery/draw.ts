/**
 * Vanilla ports of the drawings in the Next app's `components/ItemPreview.tsx`.
 * Each function returns the markup the React component renders, with the same
 * elements, attributes, numbers and Tailwind classes. `useId` is a counter.
 */

import {
  BUILDING_CSS,
  type BuildingRoles,
  roleCss,
} from "@/lib/blueprint/role";
import type { PayloadFootprint } from "@/lib/blueprint/payload";
import {
  type BlueprintShape,
  type BuildingKind,
  type BuildingRun,
  blueprintSheet,
  type PlanBox,
  planLabel,
  type TerrainDraw,
  WALL_THICKNESS,
} from "@/lib/gallery/blueprintPreview";
import type { GalaxyShape } from "@/lib/gallery/conquestGalaxy";
import type { RunShape } from "@/lib/gallery/warpathRun";
import type { RunNodeType } from "@/lib/runlite/model";

/** A resolved unit picture, as far as the drawing needs it. */
export interface Picture {
  url: string;
}

export interface UnitNameLink {
  label: string;
  href: string;
}

const EM_DASH = "—";

/** Escapes text and attribute values the way React does. */
export function esc(value: unknown): string {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#x27;");
}

type Attr = string | number | undefined;

/** Attributes in the order given, skipping undefined ones. */
function attrs(list: [string, Attr][]): string {
  return list
    .filter(([, v]) => v !== undefined)
    .map(([k, v]) => ` ${k}="${esc(v)}"`)
    .join("");
}

let counter = 0;
/** Stands in for React's `useId`: a document can hold many of these. */
function nextId(): string {
  counter += 1;
  return `coilbox-glow-${counter}`;
}

function glowFilter(id: string, deviation: number): string {
  return (
    `<defs><filter id="${esc(id)}" x="-50%" y="-50%" width="200%" height="200%">` +
    `<feGaussianBlur stdDeviation="${deviation}" result="halo"></feGaussianBlur>` +
    `<feMerge><feMergeNode in="halo"></feMergeNode><feMergeNode in="halo"></feMergeNode>` +
    `<feMergeNode in="SourceGraphic"></feMergeNode></feMerge></filter></defs>`
  );
}

function a11y(decorative: boolean, label: string): string {
  return decorative
    ? ' aria-hidden="true"'
    : ` role="img" aria-label="${esc(label)}"`;
}

/** Neutral territory. Dimmer than any faction, so held space reads first. */
const UNCLAIMED = "#6b7280";
const SYSTEM_LABEL = "#d4d4d4";
const LABEL_FONT_SIZE = 2.4;
const LABEL_GAP_FROM_STAR = 3;
const LABEL_CHAR_WIDTH = LABEL_FONT_SIZE * 0.62;
const LABEL_PADDING = 0.6;
const LABEL_ASCENT = LABEL_FONT_SIZE * 0.8;
const LABEL_DESCENT = LABEL_FONT_SIZE * 0.3;

type Anchor = "start" | "middle" | "end";
type LabelSide = "above" | "below";

interface LabelBox {
  left: number;
  right: number;
  top: number;
  bottom: number;
}

function labelAnchor(x: number): Anchor {
  return x > 0.85 ? "end" : x < 0.15 ? "start" : "middle";
}

function labelBox(
  x: number,
  baselineY: number,
  anchor: Anchor,
  width: number,
): LabelBox {
  const left =
    anchor === "start" ? x : anchor === "end" ? x - width : x - width / 2;
  return {
    left: left - LABEL_PADDING,
    right: left + width + LABEL_PADDING,
    top: baselineY - LABEL_ASCENT,
    bottom: baselineY + LABEL_DESCENT,
  };
}

function boxesOverlap(a: LabelBox, b: LabelBox): boolean {
  return (
    a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top
  );
}

interface LabelSystem {
  x: number;
  y: number;
  radius: number;
  anchor: Anchor;
  capital?: boolean;
  name?: string;
}

function placeLabels(systems: readonly LabelSystem[]): (LabelSide | null)[] {
  const order = systems
    .map((_, i) => i)
    .filter((i) => systems[i].name)
    .sort((a, b) => Number(systems[b].capital) - Number(systems[a].capital));

  const placed: LabelBox[] = [];
  const result: (LabelSide | null)[] = systems.map(() => null);

  for (const i of order) {
    const system = systems[i];
    const width = system.name!.length * LABEL_CHAR_WIDTH;
    const candidates: [LabelSide, LabelBox][] = [
      [
        "below",
        labelBox(
          system.x,
          system.y + system.radius + LABEL_GAP_FROM_STAR,
          system.anchor,
          width,
        ),
      ],
      [
        "above",
        labelBox(
          system.x,
          system.y - system.radius - LABEL_GAP_FROM_STAR,
          system.anchor,
          width,
        ),
      ],
    ];
    const fit = candidates.find(
      ([, box]) => !placed.some((p) => boxesOverlap(p, box)),
    );
    if (fit) {
      placed.push(fit[1]);
      result[i] = fit[0];
    }
  }
  return result;
}

function boxFor(system: LabelSystem & { name: string }, side: LabelSide): LabelBox {
  const baselineY =
    system.y + (side === "below" ? 1 : -1) * (system.radius + LABEL_GAP_FROM_STAR);
  return labelBox(
    system.x,
    baselineY,
    system.anchor,
    system.name.length * LABEL_CHAR_WIDTH,
  );
}

function placeReveal(
  system: LabelSystem & { name: string },
  taken: readonly LabelBox[],
): LabelSide | null {
  const below = boxFor(system, "below");
  if (!taken.some((box) => boxesOverlap(box, below))) return "below";
  const above = boxFor(system, "above");
  if (!taken.some((box) => boxesOverlap(box, above))) return "above";
  return null;
}

/** One system of a labelled galaxy, geometry resolved to viewBox units. */
export interface SystemDraw {
  x: number;
  y: number;
  radius: number;
  fill: string;
  name?: string;
  map?: string;
  capital: boolean;
  anchor: Anchor;
  textY: number | null;
}

function systemText(system: SystemDraw): string {
  return (
    `<text${attrs([
      ["x", system.x],
      ["y", system.textY!],
      ["text-anchor", system.anchor],
      ["font-size", LABEL_FONT_SIZE],
      ["fill", SYSTEM_LABEL],
      ["stroke", "#000000"],
      ["stroke-width", 0.7],
      ["stroke-linejoin", "round"],
      ["paint-order", "stroke"],
    ])}>${esc(system.name)}</text>`
  );
}

/** The galaxy drawing, and for a labelled one the systems the reveal needs. */
export function conquestGalaxyArtParts(
  shape: GalaxyShape,
  className: string,
  decorative = false,
  labelled = false,
): { html: string; systems: SystemDraw[] | null } {
  const inset = 4;
  const scale = 100 - inset * 2;
  const at = (v: number) => inset + v * scale;
  const colorOf = (faction: number | null) =>
    faction === null ? UNCLAIMED : (shape.factions[faction]?.color ?? UNCLAIMED);
  const held = shape.systems.filter((s) => s.faction !== null).length;
  const glow = nextId();

  const capitals = shape.systems
    .map((system, i) => ({ system, i }))
    .filter(({ system }) => system.capital && system.name);
  const capitalGeometry = capitals.map(({ system }) => ({
    x: at(system.x),
    y: at(system.y),
    radius: 2.1,
    anchor: labelAnchor(system.x),
    capital: true,
    name: system.name!,
  }));
  const capitalSides = labelled ? placeLabels(capitalGeometry) : [];
  const capitalBoxes = capitalGeometry.flatMap((geometry, idx) => {
    const side = capitalSides[idx];
    return side ? [boxFor(geometry, side)] : [];
  });
  const capitalSideByIndex = new Map<number, LabelSide>();
  capitals.forEach(({ i }, idx) => {
    const side = capitalSides[idx];
    if (side) capitalSideByIndex.set(i, side);
  });

  const systems: SystemDraw[] | null = labelled
    ? shape.systems.map((system, i) => {
        const radius = system.capital ? 2.1 : 1.2;
        const anchor = labelAnchor(system.x);
        const x = at(system.x);
        const y = at(system.y);
        const side = !system.name
          ? null
          : system.capital
            ? (capitalSideByIndex.get(i) ?? null)
            : placeReveal({ x, y, radius, anchor, name: system.name }, capitalBoxes);
        return {
          x,
          y,
          radius,
          fill: colorOf(system.faction),
          name: system.name,
          map: system.map,
          capital: system.capital,
          anchor,
          textY: side
            ? y + (side === "below" ? 1 : -1) * (radius + LABEL_GAP_FROM_STAR)
            : null,
        };
      })
    : null;

  const lanes = shape.lanes
    .map(([a, b]) =>
      `<line${attrs([
        ["x1", at(shape.systems[a].x)],
        ["y1", at(shape.systems[a].y)],
        ["x2", at(shape.systems[b].x)],
        ["y2", at(shape.systems[b].y)],
        ["stroke", "#404040"],
        ["stroke-width", 0.4],
      ])}></line>`,
    )
    .join("");

  let body: string;
  if (systems) {
    const stars = systems
      .map((system) =>
        `<circle${attrs([
          ["cx", system.x],
          ["cy", system.y],
          ["r", system.radius],
          ["fill", system.fill],
          ["tabindex", system.name ? 0 : undefined],
          [
            "class",
            system.name
              ? "cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
              : undefined,
          ],
        ])}>${
          system.name
            ? `<title>${esc(system.map ? `${system.name} ${EM_DASH} ${system.map}` : system.name)}</title>`
            : ""
        }</circle>`,
      )
      .join("");
    const names = systems
      .map((system) =>
        !system.name || system.textY === null || !system.capital
          ? ""
          : systemText(system),
      )
      .join("");
    body = `<g filter="url(#${esc(glow)})">${stars}</g>${names}`;
  } else {
    const stars = shape.systems
      .map((system) =>
        `<circle${attrs([
          ["cx", at(system.x)],
          ["cy", at(system.y)],
          ["r", system.capital ? 2.1 : 1.2],
          ["fill", colorOf(system.faction)],
        ])}></circle>`,
      )
      .join("");
    body = `<g filter="url(#${esc(glow)})">${stars}</g>`;
  }

  const label = `${shape.systems.length} systems joined by ${shape.lanes.length} jump lanes, ${held} of them held at the start`;
  const html =
    `<svg viewBox="${labelled ? "0 -4 100 108" : "0 0 100 100"}" class="${esc(className)}"${a11y(decorative, label)}>` +
    glowFilter(glow, 1.6) +
    lanes +
    body +
    `</svg>`;
  return { html, systems };
}

export function conquestGalaxyArt(
  shape: GalaxyShape,
  className: string,
  decorative = false,
  labelled = false,
): string {
  return conquestGalaxyArtParts(shape, className, decorative, labelled).html;
}

/** The item page's galaxy: the art, then the factions that have names. */
export function conquestGalaxy(shape: GalaxyShape): {
  html: string;
  systems: SystemDraw[] | null;
} {
  const named = shape.factions
    .map((faction, i) => ({ ...faction, slot: i }))
    .filter((faction) => faction.name);
  const art = conquestGalaxyArtParts(shape, "mx-auto w-full max-w-md", false, true);
  const legend =
    named.length > 0
      ? `<ul class="flex flex-wrap justify-center gap-x-4 gap-y-1.5">${named
          .map(
            (faction) =>
              `<li class="flex items-center gap-1.5 text-sm text-neutral-400">` +
              `<span aria-hidden="true" class="size-2 shrink-0 rounded-full" style="background:${esc(faction.color)}"></span>` +
              `${esc(faction.name)}</li>`,
          )
          .join("")}</ul>`
      : "";
  return {
    html: `<div class="flex flex-col gap-3">${art.html}${legend}</div>`,
    systems: art.systems,
  };
}

/** What each kind of stop on a run is, in the order they read on the map. */
const RUN_NODE_KINDS: { type: RunNodeType; label: string; color: string }[] = [
  { type: "start", label: "Start", color: "#e5e5e5" },
  { type: "battle", label: "Battle", color: "#2f7dff" },
  { type: "elite", label: "Elite", color: "#ffb300" },
  { type: "event", label: "Event", color: "#a855f7" },
  { type: "reward", label: "Reward", color: "#00c853" },
  { type: "shop", label: "Depot", color: "#14b8a6" },
  { type: "boss", label: "Boss", color: "#ff3524" },
];

const RUN_COLORS = new Map(RUN_NODE_KINDS.map((k) => [k.type, k.color]));

export function warpathRunArt(
  shape: RunShape,
  className: string,
  decorative = false,
): string {
  const inset = 4;
  const atX = (v: number) => inset + v * (100 - inset * 2);
  const atY = (v: number) => inset + v * (40 - inset * 2);
  const fights = shape.steps.filter(
    (s) => s.type === "battle" || s.type === "elite" || s.type === "boss",
  ).length;
  const glow = nextId();

  const routes = shape.routes
    .map(([a, b]) =>
      `<line${attrs([
        ["x1", atX(shape.steps[a].x)],
        ["y1", atY(shape.steps[a].y)],
        ["x2", atX(shape.steps[b].x)],
        ["y2", atY(shape.steps[b].y)],
        ["stroke", "#404040"],
        ["stroke-width", 0.3],
      ])}></line>`,
    )
    .join("");
  const stops = shape.steps
    .map((step) =>
      `<circle${attrs([
        ["cx", atX(step.x)],
        ["cy", atY(step.y)],
        ["r", step.type === "boss" ? 1.8 : 1.1],
        ["fill", RUN_COLORS.get(step.type) ?? UNCLAIMED],
      ])}></circle>`,
    )
    .join("");
  const label = `${shape.columns} stops from the start to the boss, ${shape.steps.length} nodes in all, ${fights} of them fights`;
  return (
    `<svg viewBox="0 0 100 40" class="${esc(className)}"${a11y(decorative, label)}>` +
    glowFilter(glow, 1.1) +
    routes +
    `<g filter="url(#${esc(glow)})">${stops}</g></svg>`
  );
}

/** The item page's run map: the art plus the legend of node colours. */
export function warpathRunMap(shape: RunShape): string {
  const kinds = RUN_NODE_KINDS.filter((k) =>
    shape.steps.some((s) => s.type === k.type),
  );
  return (
    `<div class="flex flex-col gap-3">${warpathRunArt(shape, "w-full")}` +
    `<ul class="flex flex-wrap justify-center gap-x-4 gap-y-1.5">${kinds
      .map(
        (kind) =>
          `<li class="flex items-center gap-1.5 text-sm text-neutral-400">` +
          `<span aria-hidden="true" class="size-2 shrink-0 rounded-full" style="background:${esc(kind.color)}"></span>` +
          `${esc(kind.label)}</li>`,
      )
      .join("")}</ul></div>`
  );
}

const GRID = 0.14;
const FILL = 0.3;
const OUTLINE = 0.62;
const START = 0.85;
const CORNER_PX = 2;
const CORNER_SHARE = 1 / 3;
const CLEAR_PX = 8;
const START_PX = { share: 0.22, least: 2, most: 4.5 };

/** The box the item page draws a plan in, in CSS pixels. */
export const PAGE_BOX: PlanBox = { width: 448, height: 336 };

function gridOpacity(scale: number): number {
  return GRID * Math.min(1, scale / CLEAR_PX);
}

function corner(scale: number, width: number, height: number): number {
  return Math.min(CORNER_PX / scale, Math.min(width, height) * CORNER_SHARE);
}

function startMark(scale: number): number {
  return (
    Math.min(START_PX.most, Math.max(START_PX.least, scale * START_PX.share)) /
    scale
  );
}

function threadOpacity(stops: number): number {
  return stops <= 8 ? 0.5 : Math.max(0.22, 0.5 - (stops - 8) * 0.012);
}

function pathOf(points: readonly { x: number; y: number }[], closed: boolean) {
  const d = `M${points.map(({ x, y }) => `${x} ${y}`).join(" L")}`;
  return closed ? `${d} Z` : d;
}

function terrainMark(step: TerrainDraw): string {
  const tone = step.tone === "up" ? "text-blue-500" : "text-neutral-400";
  const filled = step.tone !== "even";

  if (step.band) {
    const arrow = step.arrow
      ? `<path${attrs([
          [
            "d",
            `${pathOf([step.arrow[0], step.arrow[1]], false)} ${pathOf([step.arrow[2], step.arrow[1]], false)} ${pathOf([step.arrow[3], step.arrow[1]], false)}`,
          ],
          ["fill", "none"],
          ["stroke-opacity", START],
          ["stroke-width", 1.5],
          ["stroke-linecap", "butt"],
          ["vector-effect", "non-scaling-stroke"],
        ])}></path>`
      : "";
    return (
      `<g class="${tone}" fill="currentColor" stroke="currentColor">` +
      `<path${attrs([
        ["d", pathOf(step.band, true)],
        ["fill-opacity", filled ? FILL : 0],
        ["stroke-opacity", OUTLINE],
        ["stroke-width", 1.25],
        ["vector-effect", "non-scaling-stroke"],
      ])}></path>${arrow}</g>`
    );
  }

  if (step.shape === "wall") {
    const points =
      step.points.length === 1 ? [step.points[0], step.points[0]] : step.points;
    return `<path${attrs([
      ["class", tone],
      ["d", pathOf(points, false)],
      ["fill", "none"],
      ["stroke", "currentColor"],
      ["stroke-opacity", filled ? OUTLINE * 0.75 : FILL],
      ["stroke-width", WALL_THICKNESS],
      ["stroke-linecap", "square"],
      ["stroke-linejoin", "miter"],
    ])}></path>`;
  }

  return `<path${attrs([
    ["class", tone],
    ["d", pathOf(step.points, true)],
    ["fill", "currentColor"],
    ["fill-opacity", filled ? FILL : 0],
    ["stroke", "currentColor"],
    ["stroke-opacity", OUTLINE],
    ["stroke-width", 1.25],
    ["stroke-linejoin", "miter"],
    ["vector-effect", "non-scaling-stroke"],
  ])}></path>`;
}

export type Units = ReadonlyMap<string, Picture>;
export type Names = ReadonlyMap<string, UnitNameLink>;

const NO_UNITS: Units = new Map();
const NO_ROLES: BuildingRoles = {};

/** A layout of buildings, seen from above. */
export function blueprintLayoutArt(
  shape: BlueprintShape,
  box: PlanBox,
  className: string,
  units: Units = NO_UNITS,
  roles: BuildingRoles = NO_ROLES,
  decorative = false,
): string {
  const sheet = blueprintSheet(shape, box);
  const centres = shape.squares.map(
    (square) =>
      [square.x + square.width / 2, square.y + square.height / 2] as const,
  );
  const thread = shape.ordered && centres.length > 1 ? centres : null;

  const verticals = sheet.verticals
    .map((x) =>
      `<line${attrs([
        ["x1", x],
        ["y1", sheet.top],
        ["x2", x],
        ["y2", sheet.top + sheet.height],
        ["stroke-width", 1],
        ["vector-effect", "non-scaling-stroke"],
      ])}></line>`,
    )
    .join("");
  const horizontals = sheet.horizontals
    .map((y) =>
      `<line${attrs([
        ["x1", sheet.left],
        ["y1", y],
        ["x2", sheet.left + sheet.width],
        ["y2", y],
        ["stroke-width", 1],
        ["vector-effect", "non-scaling-stroke"],
      ])}></line>`,
    )
    .join("");

  const threadPath = thread
    ? `<path${attrs([
        ["d", `M${thread.map(([x, y]) => `${x} ${y}`).join(" L")}`],
        ["fill", "none"],
        ["stroke", "currentColor"],
        ["stroke-opacity", threadOpacity(thread.length)],
        ["stroke-width", 1.5],
        ["stroke-dasharray", "3 5"],
        ["stroke-linecap", "round"],
        ["stroke-linejoin", "round"],
        ["vector-effect", "non-scaling-stroke"],
      ])}></path>`
    : "";

  const squares = shape.squares
    .map((square) => {
      const def = square.def.toLowerCase();
      const picture = units.get(def);
      const role = roles[def];
      const color = (role && roleCss(role)) ?? "currentColor";
      const image = picture
        ? `<image${attrs([
            ["href", picture.url],
            ["x", square.x],
            ["y", square.y],
            ["width", square.width],
            ["height", square.height],
            ["preserveAspectRatio", "xMidYMid meet"],
          ])}></image>`
        : "";
      const rect = `<rect${attrs([
        ["x", square.x],
        ["y", square.y],
        ["width", square.width],
        ["height", square.height],
        ["rx", corner(sheet.scale, square.width, square.height)],
        ["fill", color],
        ["fill-opacity", picture || !square.sized ? 0 : FILL],
        ["stroke", color],
        ["stroke-opacity", OUTLINE],
        ["stroke-width", 1.25],
        ["stroke-dasharray", square.sized ? undefined : "2 2"],
        ["vector-effect", "non-scaling-stroke"],
      ])}></rect>`;
      return `<g>${image}${rect}</g>`;
    })
    .join("");

  const start = thread
    ? `<circle${attrs([
        ["cx", thread[0][0]],
        ["cy", thread[0][1]],
        ["r", startMark(sheet.scale)],
        ["fill", "currentColor"],
        ["fill-opacity", START],
      ])}></circle>`
    : "";

  return (
    `<svg viewBox="${esc(`${sheet.left} ${sheet.top} ${sheet.width} ${sheet.height}`)}" class="${esc(className)}" style="color:${BUILDING_CSS}"${a11y(decorative, planLabel(shape))}>` +
    `<g class="text-neutral-400" stroke="currentColor" stroke-opacity="${gridOpacity(sheet.scale)}">${verticals}${horizontals}</g>` +
    shape.terrain.map(terrainMark).join("") +
    threadPath +
    squares +
    start +
    `</svg>`
  );
}

function footprintLabel(footprint: PayloadFootprint | null): string {
  return footprint ? `${footprint.x} by ${footprint.z}` : "size not given";
}

function unitPicture(picture: Picture | undefined): string {
  return `<span class="size-5 shrink-0">${
    picture ? `<img src="${esc(picture.url)}" alt="" class="size-5"/>` : ""
  }</span>`;
}

function unitName(def: string, names: Names): string {
  const known = names.get(def);
  if (!known) return esc(def);
  return `<a class="hover:text-white active:text-white" href="${esc(known.href)}">${esc(known.label)}</a>`;
}

function buildingRoster(kinds: BuildingKind[], units: Units, names: Names): string {
  const pictured = kinds.some((kind) => units.has(kind.def));
  return (
    `<ul class="grid gap-x-6 gap-y-1.5 sm:grid-cols-2">${kinds
      .map(
        (kind) =>
          `<li class="flex items-center gap-2 text-sm">` +
          (pictured ? unitPicture(units.get(kind.def)) : "") +
          `<span class="min-w-0 break-all text-neutral-200">${kind.count} ${unitName(kind.def, names)}</span>` +
          `<span class="shrink-0 text-neutral-400">${esc(footprintLabel(kind.footprint))}</span></li>`,
      )
      .join("")}</ul>`
  );
}

function buildOrder(runs: BuildingRun[], units: Units, names: Names): string {
  const pictured = runs.some((run) => units.has(run.def));
  return (
    `<ol class="flex flex-col gap-1.5">${runs
      .map(
        (run) =>
          `<li class="flex items-center gap-2 text-sm">` +
          `<span class="w-10 shrink-0 text-right tabular-nums text-neutral-400">${
            run.from === run.to ? run.from : `${run.from}-${run.to}`
          }</span>` +
          (pictured ? unitPicture(units.get(run.def)) : "") +
          `<span class="min-w-0 break-all text-neutral-200">${run.count} ${unitName(run.def, names)}</span>` +
          `<span class="shrink-0 text-neutral-400">${esc(footprintLabel(run.footprint))}</span></li>`,
      )
      .join("")}</ol>`
  );
}

/** The item page's plan with its count line, designed for note and lists. */
export function blueprintLayout(
  shape: BlueprintShape,
  kinds: BuildingKind[],
  runs: BuildingRun[] | null,
  designedFor: string | null,
  units: Units,
  names: Names,
  roles: BuildingRoles,
): string {
  const buildings = shape.squares.length;
  const steps = shape.terrain.length;
  const counts = [
    ...(buildings > 0
      ? [`${buildings} ${buildings === 1 ? "building" : "buildings"}`]
      : []),
    ...(steps > 0 ? [`${steps} terrain ${steps === 1 ? "step" : "steps"}`] : []),
  ].join(", ");

  return (
    `<div class="flex flex-col gap-3">` +
    blueprintLayoutArt(
      shape,
      PAGE_BOX,
      "mx-auto aspect-[4/3] w-full max-w-md",
      units,
      roles,
    ) +
    `<p class="text-sm text-neutral-400">${esc(counts)}${
      shape.ordered && buildings > 0 ? ", in build order" : ""
    }</p>` +
    (designedFor
      ? `<p class="text-sm text-neutral-400">Designed for ${esc(designedFor)}</p>`
      : "") +
    (runs
      ? buildOrder(runs, units, names)
      : kinds.length > 0
        ? buildingRoster(kinds, units, names)
        : "") +
    `</div>`
  );
}

/** The box a setup pack draws a layout in, matching `aspect-[4/3]`. */
export const LAYOUT_BOX: PlanBox = { width: 320, height: 240 };

/** A layout a setup pack carries, as `setupPackBlueprints` returns it. */
export interface PackLayout {
  name: string;
  game: string | null;
  shape: BlueprintShape;
}

/** The Blueprints section of a setup pack page. */
export function packBlueprints(
  blueprints: PackLayout[],
  gameKey: string | null,
  units: Units,
  roles: BuildingRoles,
): string {
  const items = blueprints
    .map((layout) => {
      const buildings = layout.shape.squares.length;
      const steps = layout.shape.terrain.length;
      const ours = layout.game === null || layout.game === gameKey;
      const caption =
        [
          ...(buildings > 0
            ? [`${buildings} ${buildings === 1 ? "building" : "buildings"}`]
            : []),
          ...(steps > 0
            ? [`${steps} terrain ${steps === 1 ? "step" : "steps"}`]
            : []),
        ].join(", ") +
        (layout.shape.ordered && buildings > 0 ? ", in build order" : "");
      return (
        `<li class="flex flex-col gap-2">` +
        blueprintLayoutArt(
          layout.shape,
          LAYOUT_BOX,
          "aspect-[4/3] w-full",
          ours ? units : NO_UNITS,
          ours ? roles : NO_ROLES,
        ) +
        `<p class="break-words text-sm text-neutral-100">${esc(layout.name.trim() || "Untitled layout")}</p>` +
        `<p class="text-sm text-neutral-400">${esc(caption)}</p></li>`
      );
    })
    .join("");
  return (
    `<section class="flex flex-col gap-3">` +
    `<h2 class="text-sm uppercase tracking-wide text-neutral-400">${blueprints.length === 1 ? "Blueprint" : "Blueprints"}</h2>` +
    `<ul class="grid gap-6 sm:grid-cols-2">${items}</ul></section>`
  );
}

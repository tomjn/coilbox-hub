/**
 * What an item looks like, drawn from its own payload. No image generation, no
 * stored pictures, nothing fetched.
 *
 * Only kinds whose payload has actually been read are handled. A kind with no
 * preview renders nothing rather than an empty frame, because a placeholder box
 * reads as broken where an absence reads as unfinished.
 *
 * Note on presets: the payload carries no start positions. `Participant` holds a
 * side, a colour, an ally team and a slot, and `startPosType` only says how
 * positions get chosen at launch. So this shows the composition, which is what
 * the data actually describes.
 *
 * The map itself is a separate thing, drawn by `components/MapMinimap.tsx` from
 * BAR's map list rather than from the payload, and placed beside this on the
 * item page. Start boxes come from there too, for the same reason: the payload
 * still has none.
 */

import {
  blueprintShape,
  type BlueprintShape,
  blueprintSheet,
  planLabel,
} from "@/lib/gallery/blueprintPreview";
import { conquestGalaxy, type GalaxyShape } from "@/lib/gallery/conquestGalaxy";
import { type RunShape, warpathRun } from "@/lib/gallery/warpathRun";
import type { RunNodeType } from "@/lib/runlite/model";
import {
  participantColorCss,
  participantLabel,
  participantSideLabel,
  presetComposition,
} from "@/lib/gallery/presetPreview";
import { setupPackGameNames } from "@/lib/gallery/setupPackPreview";

function PresetPreview({ payload }: { payload: Record<string, unknown> }) {
  const composition = presetComposition(payload);
  if (!composition) return null;
  const { teams, playingCount } = composition;

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-stretch gap-2">
        {teams.map(({ allyTeam, members }, index) => (
          // The separator trails the team it follows, in the same flex item, rather
          // than leading the team after it. A wrap always breaks between items, so a
          // leading separator can start a line on its own - "v BARb" - which reads as
          // a stray character. Trailing keeps it glued to the line it ends.
          <div key={allyTeam} className="flex items-stretch gap-2">
            <div className="flex flex-col gap-1.5 rounded-md border border-neutral-800 bg-black p-3">
              {members.map((p, i) => {
                const side = participantSideLabel(p.side);
                return (
                  <div
                    key={`${allyTeam}-${i}`}
                    className="flex items-center gap-2 text-xs text-neutral-300"
                  >
                    <span
                      aria-hidden="true"
                      className="size-2.5 shrink-0 rounded-sm"
                      style={{ background: participantColorCss(p.color) }}
                    />
                    <span>{participantLabel(p)}</span>
                    {side ? <span className="text-neutral-400">{side}</span> : null}
                  </div>
                );
              })}
            </div>
            {index < teams.length - 1 ? (
              <span className="self-center text-xs text-neutral-400">v</span>
            ) : null}
          </div>
        ))}
      </div>
      <p className="text-xs text-neutral-400">
        {playingCount} playing across {teams.length}{" "}
        {teams.length === 1 ? "team" : "teams"}
      </p>
    </div>
  );
}

function SetupPackPreview({ payload }: { payload: Record<string, unknown> }) {
  const games = setupPackGameNames(payload);
  const maps = (Array.isArray(payload.maps) ? payload.maps : []) as string[];
  const engine =
    typeof payload.engineVersion === "string" ? payload.engineVersion : null;

  // A pack has no picture in it. What it has is a list of what it will install,
  // and saying that plainly is more use than a diagram of nothing.
  return (
    <dl className="grid gap-3 rounded-md border border-neutral-800 bg-black p-4 sm:grid-cols-3">
      <div className="flex flex-col gap-1">
        <dt className="text-xs uppercase tracking-wide text-neutral-400">
          {games.length === 1 ? "Game" : "Games"}
        </dt>
        <dd className="text-sm text-neutral-100">
          {games.length === 0 ? "None" : games.join(", ")}
        </dd>
      </div>
      <div className="flex flex-col gap-1">
        <dt className="text-xs uppercase tracking-wide text-neutral-400">
          Engine
        </dt>
        <dd className="text-sm text-neutral-100">
          {engine && engine !== ".spring" ? engine : "Whatever you have"}
        </dd>
      </div>
      <div className="flex flex-col gap-1">
        <dt className="text-xs uppercase tracking-wide text-neutral-400">
          {maps.length === 1 ? "Map" : "Maps"}
        </dt>
        <dd className="text-sm text-neutral-100">
          {maps.length === 0 ? "None" : maps.join(", ")}
        </dd>
      </div>
    </dl>
  );
}

function Stat({ n, label }: { n: number; label: string }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-xs uppercase tracking-wide text-neutral-400">
        {label}
      </dt>
      <dd className="text-sm text-neutral-100">{n}</dd>
    </div>
  );
}

function count(value: unknown): number {
  if (Array.isArray(value)) return value.length;
  if (value && typeof value === "object") return Object.keys(value).length;
  return 0;
}

/** Neutral territory. Dimmer than any faction, so held space reads first. */
const UNCLAIMED = "#6b7280";

/** One preview renders per page, so a fixed filter id is safe. */
const GLOW = "system-glow";

/**
 * The galaxy itself, rebuilt from the seed (see `lib/gallery/conquestGalaxy`).
 *
 * Drawn rather than described because the galaxy is the thing a person would
 * recognise. Systems sit where the generator puts them, lanes are the jumps
 * between them, and colour is who holds what on turn one. Nothing else is
 * drawn: names and maps come from installed content the hub does not have.
 *
 * The `viewBox` is the unit square the shape was fitted to, scaled up and
 * inset so a capital's ring at the edge is not clipped.
 */
function ConquestGalaxy({ shape }: { shape: GalaxyShape }) {
  const inset = 4;
  const scale = 100 - inset * 2;
  const at = (v: number) => inset + v * scale;
  const colorOf = (faction: number | null) =>
    faction === null ? UNCLAIMED : (shape.factionColors[faction] ?? UNCLAIMED);
  const held = shape.systems.filter((s) => s.faction !== null).length;

  return (
    <svg
      viewBox="0 0 100 100"
      // Capped rather than full width. The shape is square, so at the page's
      // own width it would be taller than the screen and read as a diagram
      // rather than an illustration of the thing being shared. No frame and no
      // fill: the page's own starfield is a better backdrop than a black box,
      // and a box around it made it read as a chart.
      className="mx-auto w-full max-w-md"
      role="img"
      aria-label={`${shape.systems.length} systems joined by ${shape.lanes.length} jump lanes, ${held} of them held at the start`}
    >
      <defs>
        {/* Each node is a star, so it glows. The blurred copies go under the
            original rather than replacing it, which keeps a hard point of
            light in a soft halo instead of a smudge. */}
        <filter id={GLOW} x="-50%" y="-50%" width="200%" height="200%">
          <feGaussianBlur stdDeviation={1.6} result="halo" />
          <feMerge>
            <feMergeNode in="halo" />
            <feMergeNode in="halo" />
            <feMergeNode in="SourceGraphic" />
          </feMerge>
        </filter>
      </defs>
      {shape.lanes.map(([a, b]) => (
        <line
          key={`${a}-${b}`}
          x1={at(shape.systems[a].x)}
          y1={at(shape.systems[a].y)}
          x2={at(shape.systems[b].x)}
          y2={at(shape.systems[b].y)}
          stroke="#404040"
          strokeWidth={0.4}
        />
      ))}
      <g filter={`url(#${GLOW})`}>
        {shape.systems.map((system, i) => (
          <circle
            key={i}
            cx={at(system.x)}
            cy={at(system.y)}
            // A capital is a brighter, bigger star. The glow does the rest of
            // the work, so it needs no ring to stand out.
            r={system.capital ? 2.1 : 1.2}
            fill={colorOf(system.faction)}
          />
        ))}
      </g>
    </svg>
  );
}

/**
 * What each kind of stop on a run is, in the order they read on the map.
 *
 * A run's character is how many fights it makes you take against how many
 * chances to recover, so kind is the thing worth encoding. Seven hues need
 * saying out loud, which is what the legend below is for: colour alone would
 * make this a picture nobody can read.
 */
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

/**
 * The run map, rebuilt from the seed (see `lib/gallery/warpathRun`).
 *
 * Read left to right. Every route runs forward, so where the map widens you
 * have a choice and where it narrows you do not. The boss is the last stop and
 * is drawn largest.
 *
 * Wide rather than square, because a run is up to thirteen columns of at most
 * four, and squaring it would leave the map a thin line in a tall box.
 */
function WarpathRunMap({ shape }: { shape: RunShape }) {
  const inset = 4;
  const atX = (v: number) => inset + v * (100 - inset * 2);
  const atY = (v: number) => inset + v * (40 - inset * 2);
  const kinds = RUN_NODE_KINDS.filter((k) =>
    shape.steps.some((s) => s.type === k.type),
  );
  const fights = shape.steps.filter(
    (s) => s.type === "battle" || s.type === "elite" || s.type === "boss",
  ).length;

  return (
    <div className="flex flex-col gap-3">
      <svg
        viewBox="0 0 100 40"
        className="w-full"
        role="img"
        aria-label={`${shape.columns} stops from the start to the boss, ${shape.steps.length} nodes in all, ${fights} of them fights`}
      >
        <defs>
          <filter id={GLOW} x="-50%" y="-50%" width="200%" height="200%">
            <feGaussianBlur stdDeviation={1.1} result="halo" />
            <feMerge>
              <feMergeNode in="halo" />
              <feMergeNode in="halo" />
              <feMergeNode in="SourceGraphic" />
            </feMerge>
          </filter>
        </defs>
        {shape.routes.map(([a, b]) => (
          <line
            key={`${a}-${b}`}
            x1={atX(shape.steps[a].x)}
            y1={atY(shape.steps[a].y)}
            x2={atX(shape.steps[b].x)}
            y2={atY(shape.steps[b].y)}
            stroke="#404040"
            strokeWidth={0.3}
          />
        ))}
        <g filter={`url(#${GLOW})`}>
          {shape.steps.map((step, i) => (
            <circle
              key={i}
              cx={atX(step.x)}
              cy={atY(step.y)}
              r={step.type === "boss" ? 1.8 : 1.1}
              fill={RUN_COLORS.get(step.type) ?? UNCLAIMED}
            />
          ))}
        </g>
      </svg>
      <ul className="flex flex-wrap justify-center gap-x-4 gap-y-1.5">
        {kinds.map((kind) => (
          <li
            key={kind.type}
            className="flex items-center gap-1.5 text-xs text-neutral-400"
          >
            <span
              aria-hidden="true"
              className="size-2 shrink-0 rounded-full"
              style={{ background: kind.color }}
            />
            {kind.label}
          </li>
        ))}
      </ul>
    </div>
  );
}

/** How much colour each layer of the plan takes, in the order it is drawn: a
 *  grid the eye skims, then a tinted fill under a stronger outline. Coilbox's
 *  own numbers, so a layout reads with the same weights in both. */
const GRID = 0.14;
const FILL = 0.3;
const OUTLINE = 0.62;

/** The mark the order starts on, which is the brightest thing on the sheet. */
const START = 0.85;

/**
 * Corner radius, in CSS pixels.
 *
 * Coilbox's blueprint illustration rounds a plot by two pixels, on a sheet whose
 * build squares are twenty pixels across. Carried over as a tenth of a build
 * square it came to less than a pixel wherever the plan was drawn small, and the
 * buildings read as hard squares (tomjn/coilbox#1508). Two pixels is the same
 * corner that illustration draws, at whatever size this one is drawn.
 */
const CORNER_PX = 2;

/** The most of a building the corners may eat. A radius fixed in pixels would
 *  round a one square building drawn small into a lozenge. */
const CORNER_SHARE = 1 / 3;

/** How big a build square has to be drawn for the grid to take its full weight,
 *  in CSS pixels. Under that the rules are closer together, so they are drawn
 *  lighter in proportion and the sheet keeps the same amount of ink on it rather
 *  than darkening as the base grows. */
const CLEAR_PX = 8;

/** How big the mark on the first building is, in CSS pixels: a fifth of a build
 *  square where there is room, and never so small it is lost or so big it covers
 *  the building it stands on. */
const START_PX = { share: 0.22, least: 2, most: 4.5 };

/**
 * The box the plan is drawn in, in CSS pixels.
 *
 * Declared rather than measured, so the page can be rendered on the server: the
 * plan is the only thing here that needs its own size, and this is the one place
 * the site draws one. It has to match the classes below. A narrow screen draws
 * the same sheet smaller, which leaves the geometry right and the weights a
 * little finer than they were chosen for.
 */
const PAGE_BOX = { width: 448, height: 336 };

/** The grid's weight at the size it is drawn. The rules close up as a base
 *  grows, so they lighten in step and the sheet holds the same amount of ink
 *  instead of darkening towards a wash. */
function gridOpacity(scale: number): number {
  return GRID * Math.min(1, scale / CLEAR_PX);
}

/** A building's corner radius, in build squares, from a radius in pixels. Capped
 *  against the building's short side, so a small building softens rather than
 *  rounding away. */
function corner(scale: number, width: number, height: number): number {
  return Math.min(CORNER_PX / scale, Math.min(width, height) * CORNER_SHARE);
}

/** The start mark's radius, in build squares, from a size in pixels. */
function startMark(scale: number): number {
  return (
    Math.min(START_PX.most, Math.max(START_PX.least, scale * START_PX.share)) /
    scale
  );
}

/** How strongly the order thread is drawn, given how many stops it makes. A
 *  short order is a line you can follow. A long one crosses its own path over
 *  and over, and at that length the thread stops being a route and becomes
 *  texture, so it fades to where it says the base has an order and where that
 *  order starts. */
function threadOpacity(stops: number): number {
  return stops <= 8 ? 0.5 : Math.max(0.22, 0.5 - (stops - 8) * 0.012);
}

/**
 * A layout of buildings, seen from above (see `lib/gallery/blueprintPreview`).
 *
 * One rounded square per building, each as big as the ground that building
 * stands on, laid out where the author put it, on the build grid it was drawn
 * against. There are no unit pictures here and no models, so the shape of the
 * base and the relative size of the things in it are the whole of what can be
 * shown, which is also most of what a person recognises a base by.
 *
 * This is coilbox's `src/blueprint/LayoutPlan.tsx` drawn the same way, down to
 * the grid and the weight of every mark (tomjn/coilbox#1506). The site has no
 * theme colour where the launcher does, so the plan is drawn in graphite here,
 * which is what the launcher's own art does when a theme has no hue to take.
 *
 * The `viewBox` is the whole sheet, of fixed proportions, with the base centred
 * on it and a build square of clear ground round it at the least. A base can be
 * a long thin wall or a tall narrow column, and a box the shape of the base is
 * one nobody can take in at a glance, so the sheet keeps its shape and the base
 * sits on it (tomjn/coilbox#1508).
 */
function BlueprintLayout({ shape }: { shape: BlueprintShape }) {
  const buildings = shape.squares.length;
  const sheet = blueprintSheet(shape, PAGE_BOX);
  const centres = shape.squares.map(
    (square) =>
      [square.x + square.width / 2, square.y + square.height / 2] as const,
  );
  // A thread needs somewhere to go, and one building in build order is a
  // sequence of one.
  const thread = shape.ordered && centres.length > 1 ? centres : null;

  return (
    <div className="flex flex-col gap-3">
      <svg
        viewBox={`${sheet.left} ${sheet.top} ${sheet.width} ${sheet.height}`}
        // The size {@link PAGE_BOX} describes, so the sheet is the whole of it.
        className="mx-auto aspect-[4/3] w-full max-w-md text-neutral-300"
        role="img"
        aria-label={planLabel(shape)}
      >
        <g
          className="text-neutral-400"
          stroke="currentColor"
          strokeOpacity={gridOpacity(sheet.scale)}
        >
          {sheet.verticals.map((x) => (
            <line
              key={`v${x}`}
              x1={x}
              y1={sheet.top}
              x2={x}
              y2={sheet.top + sheet.height}
              strokeWidth={1}
              vectorEffect="non-scaling-stroke"
            />
          ))}
          {sheet.horizontals.map((y) => (
            <line
              key={`h${y}`}
              x1={sheet.left}
              y1={y}
              x2={sheet.left + sheet.width}
              y2={y}
              strokeWidth={1}
              vectorEffect="non-scaling-stroke"
            />
          ))}
        </g>
        {thread ? (
          // Under the buildings. Over the top it reads as a route on a three
          // building opening and as a scribble on a base with thirty stops.
          <path
            d={`M${thread.map(([x, y]) => `${x} ${y}`).join(" L")}`}
            fill="none"
            stroke="currentColor"
            strokeOpacity={threadOpacity(thread.length)}
            strokeWidth={1.5}
            strokeDasharray="3 5"
            strokeLinecap="round"
            strokeLinejoin="round"
            vectorEffect="non-scaling-stroke"
          />
        ) : null}
        {shape.squares.map((square, i) => (
          <rect
            key={i}
            x={square.x}
            y={square.y}
            width={square.width}
            height={square.height}
            rx={corner(sheet.scale, square.width, square.height)}
            fill="currentColor"
            // A building the payload never sized is left an outline, so a guess
            // at one square does not read as a measurement.
            fillOpacity={square.sized ? FILL : 0}
            stroke="currentColor"
            strokeOpacity={OUTLINE}
            // Strokes in pixels rather than build squares, so a big base gets
            // the same hairline as a small one instead of a line thinner than
            // the screen can draw.
            strokeWidth={1.25}
            strokeDasharray={square.sized ? undefined : "2 2"}
            vectorEffect="non-scaling-stroke"
          />
        ))}
        {thread ? (
          // Where the build order starts, drawn over the building it starts on.
          <circle
            cx={thread[0][0]}
            cy={thread[0][1]}
            r={startMark(sheet.scale)}
            fill="currentColor"
            fillOpacity={START}
          />
        ) : null}
      </svg>
      <p className="text-xs text-neutral-400">
        {buildings} {buildings === 1 ? "building" : "buildings"}
        {shape.ordered ? ", in build order" : ""}
      </p>
    </div>
  );
}

/** Stat rows carry a label and a value that is not always a number, unlike
 * {@link Stat}. */
function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-xs uppercase tracking-wide text-neutral-400">
        {label}
      </dt>
      <dd className="text-sm text-neutral-100">{value}</dd>
    </div>
  );
}

/**
 * A conquest or a warpath run.
 *
 * The drawing is chosen by mode, because a galaxy and a run map have nothing in
 * common but the wrapper. The numbers are chosen by what the settings actually
 * hold, which is not the same thing: a mode from a newer coilbox has no drawing
 * either function will produce, and it should still show what can be read off
 * it rather than nothing at all.
 *
 * Ascension only appears once somebody has climbed to it. A zero there means
 * the ladder was never started, not a rung on it.
 */
function ChallengePreview({ payload }: { payload: Record<string, unknown> }) {
  const settings = (payload.settings ?? {}) as Record<string, unknown>;
  const text = (key: string) =>
    typeof settings[key] === "string" ? (settings[key] as string) : null;
  const number = (key: string) => Number(settings[key] ?? 0) || null;

  const stats = [
    ["Systems", number("nodeCount")],
    // `factionCount` is the enemy count, which the app's own wizard calls
    // "enemy factions". Labelling it "Factions" contradicted the drawing,
    // where the player is a colour on the map too.
    ["Enemies", number("factionCount")],
    ["Layout", text("layout")],
    ["Length", text("length")],
    ["Difficulty", number("difficulty")],
    ["Ascension", number("ascension")],
  ].filter(([, value]) => value !== null) as [string, string | number][];

  const galaxy = conquestGalaxy(payload);
  const run = warpathRun(payload);
  if (!galaxy && !run && stats.length === 0) return null;

  return (
    <div className="flex flex-col gap-3">
      {galaxy ? <ConquestGalaxy shape={galaxy} /> : null}
      {run ? <WarpathRunMap shape={run} /> : null}
      {stats.length > 0 ? (
        <dl className="grid gap-3 rounded-md border border-neutral-800 bg-black p-4 sm:grid-cols-3">
          {stats.map(([label, value]) => (
            <Detail key={label} label={label} value={String(value)} />
          ))}
        </dl>
      ) : null}
    </div>
  );
}

/** A scenario is a lot of moving parts and no picture. The counts say how much
 * there is to it, which is the thing somebody deciding whether to play it wants
 * to know. */
function ScenarioPreview({ payload }: { payload: Record<string, unknown> }) {
  const scenario = (payload.scenario ?? payload) as Record<string, unknown>;
  const stats: Array<[string, number]> = [
    ["Objectives", count(scenario.objectives)],
    ["Triggers", count(scenario.triggers)],
    ["Zones", count(scenario.zones)],
    ["Teams", count(scenario.teams)],
    ["Actors", count(scenario.actors)],
    ["Dialogue", count(scenario.dialogue)],
  ];
  const shown = stats.filter(([, n]) => n > 0);
  if (shown.length === 0) return null;

  return (
    <dl className="grid grid-cols-2 gap-3 rounded-md border border-neutral-800 bg-black p-4 sm:grid-cols-3">
      {shown.map(([label, n]) => (
        <Stat key={label} n={n} label={label} />
      ))}
    </dl>
  );
}

export function ItemPreview({
  kind,
  container,
}: {
  kind: string;
  container: unknown;
}) {
  const payload = (container as { payload?: unknown } | null)?.payload;
  if (typeof payload !== "object" || payload === null) return null;
  const record = payload as Record<string, unknown>;

  if (kind === "preset") return <PresetPreview payload={record} />;
  if (kind === "setup-pack") return <SetupPackPreview payload={record} />;
  if (kind === "challenge") return <ChallengePreview payload={record} />;
  if (kind === "scenario") return <ScenarioPreview payload={record} />;
  if (kind === "blueprint") {
    const shape = blueprintShape(record);
    return shape ? <BlueprintLayout shape={shape} /> : null;
  }
  return null;
}

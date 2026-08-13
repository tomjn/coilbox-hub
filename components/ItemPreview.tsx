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

import { blueprintShape, type BlueprintShape } from "@/lib/gallery/blueprintPreview";
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

/**
 * A layout of buildings, seen from above (see `lib/gallery/blueprintPreview`).
 *
 * One rounded square per building, each as big as the ground that building
 * stands on, laid out where the author put it. There are no unit pictures here
 * and no models, so the shape of the base and the relative size of the things
 * in it are the whole of what can be shown, which is also most of what a person
 * recognises a base by.
 *
 * The `viewBox` is the layout's own bounding box in build squares, so the
 * drawing is as wide or as tall as the base is.
 */
function BlueprintLayout({ shape }: { shape: BlueprintShape }) {
  const buildings = shape.squares.length;

  return (
    <div className="flex flex-col gap-3">
      <svg
        viewBox={`0 0 ${shape.width} ${shape.height}`}
        // Capped in both directions. A base can be a long thin wall or a tall
        // narrow column, and either one at the page's full width would be a
        // shape nobody can take in at a glance.
        className="mx-auto max-h-96 w-full max-w-md"
        role="img"
        aria-label={`${buildings} buildings over ${Math.round(shape.width)} by ${Math.round(shape.height)} build squares`}
      >
        {shape.squares.map((square, i) => (
          <rect
            key={i}
            x={square.x}
            y={square.y}
            width={square.width}
            height={square.height}
            rx={0.18}
            fill="#262626"
            stroke="#525252"
            strokeWidth={0.06}
          />
        ))}
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

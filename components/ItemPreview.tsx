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
 * the data actually describes, rather than a map diagram it cannot support.
 */

import { conquestGalaxy, type GalaxyShape } from "@/lib/gallery/conquestGalaxy";
import {
  participantColorCss,
  participantLabel,
  participantSideLabel,
  presetComposition,
} from "@/lib/gallery/presetPreview";

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
  const game = payload.game as { name?: string } | undefined;
  const maps = (Array.isArray(payload.maps) ? payload.maps : []) as string[];
  const engine =
    typeof payload.engineVersion === "string" ? payload.engineVersion : null;

  // A pack has no picture in it. What it has is a list of what it will install,
  // and saying that plainly is more use than a diagram of nothing.
  return (
    <dl className="grid gap-3 rounded-md border border-neutral-800 bg-black p-4 sm:grid-cols-3">
      <div className="flex flex-col gap-1">
        <dt className="text-xs uppercase tracking-wide text-neutral-400">
          Game
        </dt>
        <dd className="text-sm text-neutral-100">{game?.name ?? "None"}</dd>
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
 * A conquest or warpath run.
 *
 * The galaxy is drawn when the payload is a conquest whose seed will rebuild
 * one. The numbers stay either way, and are read from whatever the settings
 * hold rather than from the mode, so a warpath run and a mode from a newer
 * coilbox both still show what can be read off them.
 */
function ChallengePreview({ payload }: { payload: Record<string, unknown> }) {
  const settings = (payload.settings ?? {}) as Record<string, unknown>;
  const nodes = Number(settings.nodeCount ?? 0);
  const factions = Number(settings.factionCount ?? 0);
  const layout = typeof settings.layout === "string" ? settings.layout : null;
  if (!nodes && !factions && !layout) return null;
  const galaxy = conquestGalaxy(payload);

  return (
    <div className="flex flex-col gap-3">
      {galaxy ? <ConquestGalaxy shape={galaxy} /> : null}
      <dl className="grid gap-3 rounded-md border border-neutral-800 bg-black p-4 sm:grid-cols-3">
        {nodes ? <Stat n={nodes} label="Systems" /> : null}
        {/* `factionCount` is the enemy count, which the app's own wizard calls
            "enemy factions". Labelling it "Factions" contradicted the drawing,
            where the player is a colour on the map too. */}
        {factions ? <Stat n={factions} label="Enemies" /> : null}
        {layout ? <Detail label="Layout" value={layout} /> : null}
      </dl>
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
  return null;
}

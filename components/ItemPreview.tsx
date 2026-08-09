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

interface Participant {
  kind?: "you" | "ai";
  name?: string;
  ai?: { shortName?: string; name?: string };
  side?: string;
  color?: { r?: number; g?: number; b?: number };
  allyTeam?: number;
  spectator?: boolean;
}

/** Play-side colours are floats from 0 to 1, not bytes. Reading them as bytes
 * produces black for everything, which is a mistake this codebase has made
 * before in the other direction. */
function css(color: Participant["color"]): string {
  const to = (v: number | undefined) =>
    Math.round(Math.min(1, Math.max(0, v ?? 0)) * 255);
  return `rgb(${to(color?.r)} ${to(color?.g)} ${to(color?.b)})`;
}

function label(p: Participant): string {
  if (p.kind === "you") return p.name || "You";
  return p.ai?.name || p.ai?.shortName || p.name || "Open slot";
}

function PresetPreview({ payload }: { payload: Record<string, unknown> }) {
  const participants = (
    Array.isArray(payload.participants) ? payload.participants : []
  ) as Participant[];
  const playing = participants.filter((p) => !p.spectator);
  if (playing.length === 0) return null;

  const teams = new Map<number, Participant[]>();
  for (const p of playing) {
    const key = p.allyTeam ?? 0;
    teams.set(key, [...(teams.get(key) ?? []), p]);
  }
  const ordered = [...teams.entries()].sort((a, b) => a[0] - b[0]);

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-stretch gap-2">
        {ordered.map(([team, members], index) => (
          <div key={team} className="flex items-stretch gap-2">
            {index > 0 ? (
              <span className="self-center text-xs text-neutral-600">v</span>
            ) : null}
            <div className="flex flex-col gap-1.5 rounded-md border border-neutral-800 bg-black p-3">
              {members.map((p, i) => (
                <div
                  key={`${team}-${i}`}
                  className="flex items-center gap-2 text-xs text-neutral-300"
                >
                  <span
                    aria-hidden="true"
                    className="size-2.5 shrink-0 rounded-sm"
                    style={{ background: css(p.color) }}
                  />
                  <span>{label(p)}</span>
                  {p.side ? (
                    <span className="text-neutral-600">{p.side}</span>
                  ) : null}
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
      <p className="text-xs text-neutral-600">
        {playing.length} playing across {ordered.length}{" "}
        {ordered.length === 1 ? "team" : "teams"}
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
        <dt className="text-xs uppercase tracking-wide text-neutral-600">
          Game
        </dt>
        <dd className="text-sm text-neutral-300">{game?.name ?? "None"}</dd>
      </div>
      <div className="flex flex-col gap-1">
        <dt className="text-xs uppercase tracking-wide text-neutral-600">
          Engine
        </dt>
        <dd className="text-sm text-neutral-300">
          {engine && engine !== ".spring" ? engine : "Whatever you have"}
        </dd>
      </div>
      <div className="flex flex-col gap-1">
        <dt className="text-xs uppercase tracking-wide text-neutral-600">
          {maps.length === 1 ? "Map" : "Maps"}
        </dt>
        <dd className="text-sm text-neutral-300">
          {maps.length === 0 ? "None" : maps.join(", ")}
        </dd>
      </div>
    </dl>
  );
}

function Stat({ n, label }: { n: number; label: string }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-xs uppercase tracking-wide text-neutral-600">
        {label}
      </dt>
      <dd className="text-sm text-neutral-300">{n}</dd>
    </div>
  );
}

function count(value: unknown): number {
  if (Array.isArray(value)) return value.length;
  if (value && typeof value === "object") return Object.keys(value).length;
  return 0;
}

/** A conquest or warpath run. Node count and branching are what make one run
 * visibly different from another, and they are the numbers the generator was
 * given rather than anything derived. */
function ChallengePreview({ payload }: { payload: Record<string, unknown> }) {
  const settings = (payload.settings ?? {}) as Record<string, unknown>;
  const nodes = Number(settings.nodeCount ?? 0);
  const factions = Number(settings.factionCount ?? 0);
  const layout = typeof settings.layout === "string" ? settings.layout : null;
  if (!nodes && !factions && !layout) return null;

  return (
    <dl className="grid gap-3 rounded-md border border-neutral-800 bg-black p-4 sm:grid-cols-3">
      {nodes ? <Stat n={nodes} label="Systems" /> : null}
      {factions ? <Stat n={factions} label="Factions" /> : null}
      {layout ? (
        <div className="flex flex-col gap-1">
          <dt className="text-xs uppercase tracking-wide text-neutral-600">
            Layout
          </dt>
          <dd className="text-sm text-neutral-300">{layout}</dd>
        </div>
      ) : null}
    </dl>
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

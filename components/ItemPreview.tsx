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
          <div key={allyTeam} className="flex items-stretch gap-2">
            {index > 0 ? (
              <span className="self-center text-xs text-neutral-400">v</span>
            ) : null}
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
          <dt className="text-xs uppercase tracking-wide text-neutral-400">
            Layout
          </dt>
          <dd className="text-sm text-neutral-100">{layout}</dd>
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

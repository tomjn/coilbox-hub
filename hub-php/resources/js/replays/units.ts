/**
 * The replay map card's units mode (#668), as Coilbox's `ReplayMapUnits` and
 * `replayMapView/`: each unit as its footprint in its team's colour, moving
 * over the minimap, with a timeline to play, pause and scrub.
 *
 * The track is read from its mirror a window at a time (`track.ts`). Coilbox
 * also draws projectiles, wrecks, explosions and game icons. The hub has no
 * unit icons, so it draws footprints, and draws units only.
 */
import { browserInflate, buildAt, forEachAliveUnit, httpFetcher, teamAt, Track, type TrackWindow, transformAt, type UnitPlace, windowOfFrame } from "./track";

/** `LEAST_HALF_MARK`: half the smallest mark in CSS pixels, so a small unit still shows. */
const LEAST_HALF_MARK = 1.5;
/** `FACING_REACH`: how far a facing line runs past the front of its mark, at the least. */
const FACING_REACH = 2.5;
/** `FAINTEST`: how much of a unit shows when its construction has just started. */
const FAINTEST = 0.25;
const DARK_EDGE = "rgba(9, 13, 22, 0.9)";
const PALE_EDGE = "rgba(226, 232, 240, 0.85)";
/** How much the minimap is dimmed under the units, so they stand out. Chosen by eye. */
const DIM = "rgba(0, 0, 0, 0.35)";

interface TeamStyle {
  fill: string;
  edge: string;
}

/** `styleOfColour`: a colour too dark to see on the dimmed map gets a pale edge. */
function styleOfColour(css: string): TeamStyle {
  const hex = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(css);
  const rgb = hex ? hex.slice(1).map((h) => parseInt(h, 16)) : /^rgb\((\d+), (\d+), (\d+)\)$/.exec(css)?.slice(1).map(Number);
  if (!rgb) return { fill: css, edge: DARK_EDGE };
  const luminance = (0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]) / 255;
  return { fill: css, edge: luminance < 0.25 ? PALE_EDGE : DARK_EDGE };
}

/** `buildAlpha`: never as solid as a finished unit, so the two are told apart. */
function buildAlpha(build: number): number {
  return build >= 1 ? 1 : FAINTEST + (0.85 - FAINTEST) * Math.max(build, 0);
}

/** m:ss, or h:mm:ss from an hour, as the rest of the page writes times. */
export function clockTime(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds));
  const pad = (n: number) => String(n).padStart(2, "0");
  return s >= 3600 ? `${Math.floor(s / 3600)}:${pad(Math.floor(s / 60) % 60)}:${pad(s % 60)}` : `${Math.floor(s / 60)}:${pad(s % 60)}`;
}

const place: UnitPlace = { x: 0, z: 0, fx: 0, fz: 0 };

/**
 * `paintUnits` for footprints: one path a team for its finished units, filled
 * once, then each unit under construction on its own with its own opacity.
 */
function paint(ctx: CanvasRenderingContext2D, track: Track, w: TrackWindow, frame: number, styles: Map<number, TeamStyle>, pixelRatio: number): number {
  const { width, height } = ctx.canvas;
  ctx.setTransform(1, 0, 0, 1, 0, 0);
  ctx.globalAlpha = 1;
  ctx.clearRect(0, 0, width, height);
  ctx.fillStyle = DIM;
  ctx.fillRect(0, 0, width, height);
  const { info } = track;
  const sx = width / (info.mapWidth || 1);
  const sz = height / (info.mapHeight || 1);
  const least = LEAST_HALF_MARK * pixelRatio;

  const byTeam = new Map<number, { units: number[]; building: number[] }>();
  const at = new Map<number, { x: number; z: number; fx: number; fz: number; along: number; across: number; build: number }>();
  forEachAliveUnit(w, frame, (unit) => {
    if (!transformAt(w, unit, frame, place)) return;
    const def = w.unitDef[unit];
    const length = Math.hypot(place.fx, place.fz);
    const build = buildAt(w, unit, frame);
    at.set(unit, {
      x: place.x * sx,
      z: place.z * sz,
      fx: length < 1e-6 ? 0 : place.fx / length,
      fz: length < 1e-6 ? 1 : place.fz / length,
      across: Math.max(((info.footprintAcross?.[def] ?? 0) / 2) * sx, least),
      along: Math.max(((info.footprintAlong?.[def] ?? 0) / 2) * sz, least),
      build,
    });
    const team = teamAt(w, unit, frame);
    const group = byTeam.get(team) ?? { units: [], building: [] };
    (build >= 1 ? group.units : group.building).push(unit);
    byTeam.set(team, group);
  });

  const outline = (unit: number) => {
    const u = at.get(unit)!;
    const ax = u.fx * u.along;
    const az = u.fz * u.along;
    const bx = -u.fz * u.across;
    const bz = u.fx * u.across;
    ctx.moveTo(u.x + ax + bx, u.z + az + bz);
    ctx.lineTo(u.x + ax - bx, u.z + az - bz);
    ctx.lineTo(u.x - ax - bx, u.z - az - bz);
    ctx.lineTo(u.x - ax + bx, u.z - az + bz);
    ctx.closePath();
  };
  const facing = (unit: number) => {
    const u = at.get(unit)!;
    const reach = u.along + Math.max(FACING_REACH * pixelRatio, u.along * 0.6);
    ctx.moveTo(u.x, u.z);
    ctx.lineTo(u.x + u.fx * reach, u.z + u.fz * reach);
  };

  // Teams in rising order, so the order of drawing does not change from frame to frame.
  for (const team of [...byTeam.keys()].sort((a, b) => a - b)) {
    const style = styles.get(team) ?? { fill: "rgb(148, 156, 170)", edge: DARK_EDGE };
    const { units, building } = byTeam.get(team)!;
    ctx.lineWidth = pixelRatio;
    if (units.length > 0) {
      ctx.globalAlpha = 1;
      ctx.beginPath();
      units.forEach(outline);
      ctx.strokeStyle = style.edge;
      ctx.stroke();
      ctx.fillStyle = style.fill;
      ctx.fill();
      ctx.beginPath();
      units.forEach(facing);
      ctx.strokeStyle = style.fill;
      ctx.stroke();
    }
    for (const unit of building) {
      ctx.globalAlpha = buildAlpha(at.get(unit)!.build);
      ctx.beginPath();
      outline(unit);
      ctx.strokeStyle = style.edge;
      ctx.stroke();
      ctx.fillStyle = style.fill;
      ctx.fill();
    }
  }
  ctx.globalAlpha = 1;
  return at.size;
}

function mount(root: HTMLElement): void {
  const url = root.dataset.track;
  if (!url) return;
  const colours = JSON.parse(root.dataset.colours ?? "{}") as Record<string, string>;
  const pick = <T extends Element>(selector: string) => root.querySelector<T>(selector);
  const modes = pick<HTMLElement>("[data-units-modes]");
  const canvas = pick<HTMLCanvasElement>("[data-units-canvas]");
  const controls = pick<HTMLElement>("[data-units-controls]");
  const status = pick<HTMLElement>("[data-units-status]");
  const failed = pick<HTMLElement>("[data-units-failed]");
  const play = pick<HTMLButtonElement>("[data-play]");
  const scrub = pick<HTMLInputElement>("[data-scrub]");
  const time = pick<HTMLOutputElement>("[data-time]");
  const speed = pick<HTMLSelectElement>("[data-speed]");
  const ctx = canvas?.getContext("2d");
  if (!modes || !canvas || !controls || !status || !failed || !play || !scrub || !time || !speed || !ctx) return;
  const heatViews = [...root.querySelectorAll<HTMLElement>("[data-heat-view]")];
  const unitViews = [...root.querySelectorAll<HTMLElement>("[data-units-view]")];
  modes.hidden = false;

  let track: Track | null = null;
  let opening: Promise<Track | null> | null = null;
  let frame = 0;
  let playing = false;
  let last = 0;
  let drawn = -1;
  let shown: "heat" | "units" = "heat";
  let broken = false;
  const styles = new Map<number, TeamStyle>();

  const say = (text: string) => {
    status.textContent = text;
    status.hidden = text === "";
  };

  const open = () => {
    opening ??= Track.open(httpFetcher(url), browserInflate).then(
      (opened) => {
        track = opened;
        for (const team of opened.info.teams) {
          styles.set(team.id, styleOfColour(colours[team.id] ?? `rgb(${team.colour.join(", ")})`));
        }
        const { firstFrame, lastFrame } = opened.header;
        frame = firstFrame;
        scrub.min = String(firstFrame);
        scrub.max = String(lastFrame);
        scrub.value = String(firstFrame);
        say("");
        controls.hidden = shown !== "units";
        draw();
        return opened;
      },
      () => {
        // Today's mirrors send no CORS headers, so the browser refuses the track (tomjn/coilbox-mirror#9).
        broken = true;
        say("");
        canvas.hidden = true;
        controls.hidden = true;
        failed.hidden = shown !== "units";
        return null;
      },
    );
    return opening;
  };

  const resize = () => {
    const ratio = window.devicePixelRatio || 1;
    const width = Math.round(canvas.clientWidth * ratio);
    const height = Math.round(canvas.clientHeight * ratio);
    if (canvas.width !== width || canvas.height !== height) {
      canvas.width = width;
      canvas.height = height;
      drawn = -1;
    }
  };

  /** Draws the frame if its window is decoded, and asks for it and the next one if not. */
  const draw = () => {
    if (!track) return;
    const { header } = track;
    const k = windowOfFrame(header, frame);
    const w = track.peek(k);
    if (k + 1 < header.windowCount && frame > header.firstFrame + (k + 0.5) * header.windowLength) void track.window(k + 1);
    time.textContent = `${clockTime((frame - header.firstFrame) / header.framesPerSecond)} / ${clockTime((header.lastFrame - header.firstFrame) / header.framesPerSecond)}`;
    if (!w) {
      void track.window(k).then(draw, () => say("A part of the track could not be read."));
      return;
    }
    resize();
    if (Math.round(frame) === drawn) return;
    drawn = Math.round(frame);
    paint(ctx, track, w, drawn, styles, window.devicePixelRatio || 1);
  };

  const setPlaying = (on: boolean) => {
    playing = on;
    play.textContent = on ? "Pause" : "Play";
    if (on) {
      last = performance.now();
      requestAnimationFrame(tick);
    }
  };

  const tick = (now: number) => {
    if (!playing || !track) return;
    const { header } = track;
    const w = track.peek(windowOfFrame(header, frame));
    // While a window loads the clock waits, so the picture never skips a stretch of the match.
    if (w) frame += ((now - last) / 1000) * header.framesPerSecond * Number(speed.value);
    last = now;
    if (frame >= header.lastFrame) {
      frame = header.lastFrame;
      setPlaying(false);
    }
    scrub.value = String(Math.round(frame));
    draw();
    if (playing) requestAnimationFrame(tick);
  };

  const showMode = (mode: "heat" | "units") => {
    shown = mode;
    for (const button of modes.querySelectorAll<HTMLButtonElement>("[data-mode]")) button.setAttribute("aria-pressed", String(button.dataset.mode === mode));
    for (const el of heatViews) el.hidden = mode === "units";
    for (const el of unitViews) el.hidden = mode !== "units";
    failed.hidden = mode !== "units" || !broken;
    if (mode === "heat") {
      setPlaying(false);
      canvas.hidden = true;
      controls.hidden = true;
      status.hidden = true;
      return;
    }
    if (broken) return;
    canvas.hidden = false;
    if (track) {
      controls.hidden = false;
      draw();
    } else {
      say("Reading the track…");
      void open();
    }
  };

  modes.addEventListener("click", (event) => {
    const mode = (event.target as HTMLElement).closest<HTMLButtonElement>("[data-mode]")?.dataset.mode;
    if (mode === "heat" || mode === "units") showMode(mode);
  });
  play.addEventListener("click", () => {
    if (!track) return;
    if (!playing && frame >= track.header.lastFrame) frame = track.header.firstFrame;
    setPlaying(!playing);
  });
  scrub.addEventListener("input", () => {
    frame = Number(scrub.value);
    draw();
  });
  new ResizeObserver(() => {
    if (shown === "units") draw();
  }).observe(canvas);
}

for (const root of document.querySelectorAll<HTMLElement>("[data-replay-units]")) mount(root);

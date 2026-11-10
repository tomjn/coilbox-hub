/**
 * Draws the generated pictures in the browser: gallery cards, the challenge and
 * blueprint drawings on an item page, and a setup pack's blueprint layouts. PHP
 * emits a placeholder element carrying JSON and this replaces it.
 */

import type { BuildingRoles } from "@/lib/blueprint/role";
import {
  blueprintBuildOrder,
  blueprintDesignedFor,
  blueprintRoster,
  blueprintShape,
} from "@/lib/gallery/blueprintPreview";
import { setupPackBlueprints } from "@/lib/gallery/setupPackPreview";
import { conquestGalaxy as buildGalaxy } from "@/lib/gallery/conquestGalaxy";
import { warpathRun as buildRun } from "@/lib/gallery/warpathRun";
import { type CardShapeParts, cardShape } from "./cardShape";
import {
  blueprintLayout,
  blueprintLayoutArt,
  conquestGalaxy,
  conquestGalaxyArt,
  packBlueprints,
  type Names,
  type Picture,
  type SystemDraw,
  type UnitNameLink,
  type Units,
  warpathRunArt,
  warpathRunMap,
} from "./draw";

/** A gallery card's art frame, from `components/ItemCardArt.tsx`. */
const FRAME =
  "aspect-[3/2] w-full shrink-0 overflow-hidden rounded-md border border-neutral-800 bg-black";

/** The box a card draws a blueprint in, in CSS pixels. */
const CARD_BOX = { width: 315, height: 210 };

/** The box a setup pack draws a layout in, from `SetupPackContents.tsx`. */
const LAYOUT_BOX = { width: 320, height: 240 };

function readJson<T>(element: Element, name: string): T | null {
  try {
    return JSON.parse(element.getAttribute(name) ?? "") as T;
  } catch {
    return null;
  }
}

function fragment(html: string): DocumentFragment {
  const template = document.createElement("template");
  template.innerHTML = html;
  return template.content;
}

function mountCard(element: HTMLElement): void {
  const data = readJson<{
    kind: string;
    mode: string | null;
    parts: CardShapeParts;
    roles: BuildingRoles | [];
  }>(element, "data-card-shape");
  if (!data) return;

  const roles = Array.isArray(data.roles) ? {} : (data.roles ?? {});
  const shape = cardShape({ kind: data.kind, mode: data.mode }, data.parts, roles);
  if (!shape) return;

  const art =
    shape.type === "galaxy"
      ? conquestGalaxyArt(shape.galaxy, "size-full", true)
      : shape.type === "run"
        ? warpathRunArt(shape.run, "size-full", true)
        : blueprintLayoutArt(
            shape.layout,
            CARD_BOX,
            "size-full",
            undefined,
            shape.roles,
            true,
          );
  element.className = FRAME;
  element.innerHTML = art;
}

/** Shows a system's name while it is pointed at, focused or tapped. */
function wireReveal(root: Element, systems: SystemDraw[]): void {
  const svg = root.querySelector("svg");
  if (!svg) return;
  const stars = svg.querySelectorAll("g[filter] > circle");
  let shown: SVGTextElement | null = null;

  const clear = (current: SVGTextElement | null) => {
    if (current && current === shown) {
      current.remove();
      shown = null;
    }
  };

  stars.forEach((star, i) => {
    const system = systems[i];
    if (!system?.name || system.capital || system.textY === null) return;
    const show = () => {
      shown?.remove();
      const text = document.createElementNS("http://www.w3.org/2000/svg", "text");
      text.setAttribute("x", String(system.x));
      text.setAttribute("y", String(system.textY));
      text.setAttribute("text-anchor", system.anchor);
      text.setAttribute("font-size", "2.4");
      text.setAttribute("fill", "#d4d4d4");
      text.setAttribute("stroke", "#000000");
      text.setAttribute("stroke-width", "0.7");
      text.setAttribute("stroke-linejoin", "round");
      text.setAttribute("paint-order", "stroke");
      text.textContent = system.name ?? "";
      svg.appendChild(text);
      shown = text;
    };
    const hide = () => clear(shown);
    star.addEventListener("mouseenter", show);
    star.addEventListener("mouseleave", hide);
    star.addEventListener("focus", show);
    star.addEventListener("blur", hide);
    star.addEventListener("click", show);
  });
}

function mountChallenge(element: HTMLElement): void {
  const data = readJson<{ payload: Record<string, unknown> }>(
    element,
    "data-challenge-drawing",
  );
  const payload = data?.payload;
  const galaxy = payload ? buildGalaxy(payload) : null;
  const run = payload ? buildRun(payload) : null;
  if (!galaxy && !run) {
    element.remove();
    return;
  }

  const nodes: Node[] = [];
  if (galaxy) {
    const drawn = conquestGalaxy(galaxy);
    const content = fragment(drawn.html);
    if (drawn.systems) {
      const wrapper = content.firstElementChild;
      if (wrapper) wireReveal(wrapper, drawn.systems);
    }
    nodes.push(content);
  }
  if (run) nodes.push(fragment(warpathRunMap(run)));
  element.replaceWith(...nodes);
}

function pictures(raw: Record<string, { from?: string; url?: string }> | undefined): Units {
  const units = new Map<string, Picture>();
  for (const [def, picture] of Object.entries(raw ?? {})) {
    if ((picture.from === "static" || picture.from === "bucket") && picture.url) {
      units.set(def, { url: picture.url });
    }
  }
  return units;
}

function mountBlueprint(element: HTMLElement): void {
  const data = readJson<{
    payload: Record<string, unknown>;
    units?: Record<string, { from?: string; url?: string }>;
    names?: Record<string, UnitNameLink>;
    roles?: BuildingRoles | [];
  }>(element, "data-blueprint-preview");
  const shape = data ? blueprintShape(data.payload) : null;
  if (!data || !shape) {
    element.remove();
    return;
  }

  const names: Names = new Map(Object.entries(data.names ?? {}));
  const roles = Array.isArray(data.roles) ? {} : (data.roles ?? {});
  element.replaceWith(
    fragment(
      blueprintLayout(
        shape,
        blueprintRoster(data.payload),
        blueprintBuildOrder(data.payload),
        blueprintDesignedFor(data.payload),
        pictures(data.units),
        names,
        roles,
      ),
    ),
  );
}

/** A setup pack's blueprint, drawn at the pack's page box with no pictures. */
function mountLayout(element: HTMLElement): void {
  const data = readJson<{
    payload: Record<string, unknown>;
    roles?: BuildingRoles | [];
  }>(element, "data-blueprint-layout");
  const shape = data ? blueprintShape(data.payload) : null;
  if (!data || !shape) return;

  const roles = Array.isArray(data.roles) ? {} : (data.roles ?? {});
  element.replaceWith(
    fragment(
      blueprintLayoutArt(shape, LAYOUT_BOX, "aspect-[4/3] w-full", undefined, roles),
    ),
  );
}

/** A setup pack's Blueprints section, or nothing when it carries none. */
function mountPack(element: HTMLElement): void {
  const data = readJson<{
    payload: Record<string, unknown>;
    gameKey?: string | null;
    units?: Record<string, { from?: string; url?: string }>;
    roles?: BuildingRoles | [];
  }>(element, "data-pack-blueprints");
  const blueprints = data?.payload ? setupPackBlueprints(data.payload) : [];
  if (!data || blueprints.length === 0) {
    element.remove();
    return;
  }
  const roles = Array.isArray(data.roles) ? {} : (data.roles ?? {});
  element.replaceWith(
    fragment(packBlueprints(blueprints, data.gameKey ?? null, pictures(data.units), roles)),
  );
}

function mount<T extends HTMLElement>(selector: string, draw: (el: T) => void): void {
  document.querySelectorAll<T>(selector).forEach((element) => {
    try {
      draw(element);
    } catch (error) {
      console.error("gallery drawing failed", error);
    }
  });
}

function mountAll(): void {
  mount("[data-card-shape]", mountCard);
  mount("[data-challenge-drawing]", mountChallenge);
  mount("[data-blueprint-preview]", mountBlueprint);
  mount("[data-blueprint-layout]", mountLayout);
  mount("[data-pack-blueprints]", mountPack);
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", mountAll);
} else {
  mountAll();
}

"use client";

import { useActionState, useRef, useState } from "react";
import { editConquestFactions } from "@/app/games/actions";
import type { ConquestFaction } from "@/lib/games/catalog";
import type { GameFormState } from "@/lib/games/formState";

/**
 * The ordered list of a game's own conquest factions (#393): a name plus an
 * optional colour and an optional in-game side, editable by the game's owner
 * or a moderator on the same page as its words and links.
 *
 * Order is the list's own meaning - `factionSpecs` in `lib/conquest/names.ts`
 * fills the player's faction first, then enemies, in this order - so unlike
 * the fixed link rows above it, this needs an add button, a remove button and
 * a way to move a row, which is what makes it a client component holding its
 * own state rather than a plain form. The whole list travels as one JSON
 * field, rebuilt from state on every change: a set of parallel
 * name[]/color[]/side[] fields would still have to be zipped back into this
 * shape on the server, for no benefit over sending the array once.
 *
 * Capped at 12 entries, the same bound `linksFromForm` puts on the links rows.
 */

const CONTROL =
  "w-full rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-500 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400";

const ROW_BUTTON =
  "rounded-md border border-neutral-800 px-2 py-1 text-xs text-neutral-400 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200 disabled:opacity-40";

const MAX_FACTIONS = 12;

interface Row {
  id: number;
  name: string;
  color: string;
  side: string;
}

function toPayload(rows: Row[]): string {
  const factions = rows
    .map((row) => ({ name: row.name.trim(), color: row.color.trim(), side: row.side.trim() }))
    .filter((faction) => faction.name !== "")
    .map((faction) => ({
      name: faction.name,
      ...(faction.color ? { color: faction.color } : {}),
      ...(faction.side ? { side: faction.side } : {}),
    }));
  return JSON.stringify(factions);
}

export function ConquestFactionsForm({
  shortname,
  factions,
}: {
  shortname: string;
  factions: ConquestFaction[];
}) {
  const [rows, setRows] = useState<Row[]>(() =>
    factions.map((faction, index) => ({
      id: index,
      name: faction.name,
      color: faction.color ?? "",
      side: faction.side ?? "",
    })),
  );
  // Only ever read or bumped from an event handler, never from render, so an
  // added row's id cannot collide with one already in state: seeded past the
  // initial rows above, so an add right after mount cannot reuse an id either.
  const nextId = useRef(factions.length);
  const [state, action, pending] = useActionState<GameFormState | null, FormData>(
    editConquestFactions,
    null,
  );

  const update = (id: number, patch: Partial<Omit<Row, "id">>) =>
    setRows((current) => current.map((row) => (row.id === id ? { ...row, ...patch } : row)));

  const remove = (id: number) => setRows((current) => current.filter((row) => row.id !== id));

  const move = (index: number, delta: number) =>
    setRows((current) => {
      const target = index + delta;
      if (target < 0 || target >= current.length) return current;
      const next = current.slice();
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });

  const add = () =>
    setRows((current) =>
      current.length >= MAX_FACTIONS
        ? current
        : [...current, { id: nextId.current++, name: "", color: "", side: "" }],
    );

  return (
    <form action={action} className="flex flex-col gap-3">
      <input type="hidden" name="shortname" value={shortname} />
      <input type="hidden" name="factions" value={toPayload(rows)} />

      <p className="text-sm text-neutral-500">
        Named in the order a galaxy fills them: the first is the player&apos;s own faction, the
        rest are enemies. Two factions can name the same in-game side.
      </p>

      <div className="flex flex-col gap-2">
        {rows.map((row, index) => (
          <div key={row.id} className="flex items-center gap-2">
            <input
              value={row.name}
              onChange={(event) => update(row.id, { name: event.target.value })}
              placeholder="Faction name"
              maxLength={256}
              aria-label={`Faction ${index + 1} name`}
              className={`${CONTROL} w-40`}
            />
            <input
              value={row.color}
              onChange={(event) => update(row.id, { color: event.target.value })}
              placeholder="#rrggbb"
              pattern="^#[0-9a-fA-F]{6}$"
              aria-label={`Faction ${index + 1} colour`}
              className={`${CONTROL} w-28`}
            />
            <input
              value={row.side}
              onChange={(event) => update(row.id, { side: event.target.value })}
              placeholder="In-game side"
              maxLength={64}
              aria-label={`Faction ${index + 1} in-game side`}
              className={`${CONTROL} w-32`}
            />
            <button
              type="button"
              onClick={() => move(index, -1)}
              disabled={index === 0}
              aria-label={`Move faction ${index + 1} up`}
              className={ROW_BUTTON}
            >
              Up
            </button>
            <button
              type="button"
              onClick={() => move(index, 1)}
              disabled={index === rows.length - 1}
              aria-label={`Move faction ${index + 1} down`}
              className={ROW_BUTTON}
            >
              Down
            </button>
            <button
              type="button"
              onClick={() => remove(row.id)}
              aria-label={`Remove faction ${index + 1}`}
              className={ROW_BUTTON}
            >
              Remove
            </button>
          </div>
        ))}
        {rows.length === 0 ? (
          <p className="text-sm text-neutral-500">No factions named yet.</p>
        ) : null}
      </div>

      <button
        type="button"
        onClick={add}
        disabled={rows.length >= MAX_FACTIONS}
        className="self-start rounded-md border border-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-40"
      >
        Add faction
      </button>

      <div className="flex items-center gap-3">
        <button
          type="submit"
          disabled={pending}
          className="self-start rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-60"
        >
          {pending ? "Saving…" : "Save factions"}
        </button>
        {state ? (
          <p role={state.ok ? "status" : "alert"} className={`text-sm ${state.ok ? "text-neutral-300" : "text-red-400"}`}>
            {state.message}
          </p>
        ) : null}
      </div>
    </form>
  );
}

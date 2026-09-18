"use client";

import { useActionState, useRef, useState } from "react";
import { editDownloadSources } from "@/app/games/actions";
import { type GameDownload, MAX_DOWNLOADS } from "@/lib/games/download";
import type { GameFormState } from "@/lib/games/formState";

/**
 * The ordered list of places coilbox can fetch this game (#396).
 *
 * Order is the order to try, not a ranking anybody argues about: coilbox walks
 * a game's sources until one yields the file. So this needs an add button, a
 * remove button and a way to move a row, which is what makes it a client
 * component holding its own state rather than the plain fieldset the single
 * source used to be. The whole list travels as one JSON field, the way the
 * conquest faction list beside it does.
 *
 * ## One box changes meaning with the kind
 *
 * A github source wants part of a release archive's filename and a url source
 * wants the filename to save as. They are different questions, so the box is
 * relabelled rather than shared, and a rapid source shows neither.
 *
 * Text typed under one kind stays in state when the kind changes, so somebody
 * who picked the wrong kind and went back does not have to retype it. The
 * server drops whatever the chosen kind has no use for.
 *
 * ## The rows sit outside the form on purpose
 *
 * React resets a form after its action runs, and it puts a controlled text box
 * back afterwards but not a controlled select: the select drops to its first
 * option while React's own state still holds the kind that was picked. A save
 * refused for a bad row is exactly when that shows, so somebody correcting one
 * source would watch another turn into a rapid tag under them.
 *
 * None of these controls is a form field. The list travels as one hidden
 * value, so the form holds that and the button, and the rows are an editor
 * beside it. Nothing to reset, nothing to put back.
 */

const CONTROL =
  "w-full rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-500 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400";

const ROW_BUTTON =
  "rounded-md border border-neutral-800 px-2 py-1 text-xs text-neutral-400 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200 disabled:opacity-40";

interface Row {
  id: number;
  kind: string;
  value: string;
  asset: string;
  filename: string;
}

/** What the value box asks for, and what the detail box asks for, per kind. */
const PROMPTS: Record<string, { value: string; detail: string | null }> = {
  rapid: { value: "metalfactions:stable", detail: null },
  url: { value: "https://example.com/game.sdz", detail: "Save as, e.g. game.sdz" },
  github: { value: "owner/repo", detail: "Release file, e.g. TAP_v4" },
};

function toPayload(rows: Row[]): string {
  return JSON.stringify(
    rows
      .filter((row) => row.value.trim() !== "")
      .map((row) => ({
        kind: row.kind,
        value: row.value.trim(),
        asset: row.asset.trim(),
        filename: row.filename.trim(),
      })),
  );
}

export function DownloadSourcesForm({
  shortname,
  downloads,
}: {
  shortname: string;
  downloads: GameDownload[];
}) {
  const [rows, setRows] = useState<Row[]>(() =>
    downloads.map((download, index) => ({
      id: index,
      kind: download.kind,
      value: download.value,
      asset: download.asset ?? "",
      filename: download.filename ?? "",
    })),
  );
  // Only ever read or bumped from an event handler, never from render, so an
  // added row's id cannot collide with one already in state.
  const nextId = useRef(downloads.length);
  const [state, action, pending] = useActionState<GameFormState | null, FormData>(
    editDownloadSources,
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
      current.length >= MAX_DOWNLOADS
        ? current
        : [...current, { id: nextId.current++, kind: "rapid", value: "", asset: "", filename: "" }],
    );

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-neutral-500">
        Every place this game can be fetched from, best first. Coilbox tries them in this order
        and stops at the first that works, so a repo with no release archives is worth keeping
        below a rapid tag rather than instead of it.
      </p>

      <div className="flex flex-col gap-2">
        {rows.map((row, index) => {
          const prompt = PROMPTS[row.kind] ?? PROMPTS.rapid;
          return (
            <div key={row.id} className="flex flex-wrap items-center gap-2">
              {/* The width sits on a wrapper rather than on the control, because
                  every control carries w-full from CONTROL and which of the two
                  wins is down to the order Tailwind happened to emit them in. */}
              <div className="w-32 shrink-0">
                <select
                  value={row.kind}
                  onChange={(event) => update(row.id, { kind: event.target.value })}
                  aria-label={`Source ${index + 1} kind`}
                  className={CONTROL}
                >
                  <option value="rapid">Rapid tag</option>
                  <option value="url">Address</option>
                  <option value="github">GitHub repo</option>
                </select>
              </div>
              <div className="min-w-48 flex-1">
                <input
                  value={row.value}
                  onChange={(event) => update(row.id, { value: event.target.value })}
                  placeholder={prompt.value}
                  maxLength={512}
                  aria-label={`Source ${index + 1} tag, address or repo`}
                  className={CONTROL}
                />
              </div>
              {prompt.detail ? (
                <div className="w-44 shrink-0">
                  <input
                    value={row.kind === "url" ? row.filename : row.asset}
                    onChange={(event) =>
                      update(
                        row.id,
                        row.kind === "url"
                          ? { filename: event.target.value }
                          : { asset: event.target.value },
                      )
                    }
                    placeholder={prompt.detail}
                    maxLength={256}
                    aria-label={
                      row.kind === "url"
                        ? `Source ${index + 1} filename to save as`
                        : `Source ${index + 1} release file`
                    }
                    className={CONTROL}
                  />
                </div>
              ) : null}
              <button
                type="button"
                onClick={() => move(index, -1)}
                disabled={index === 0}
                aria-label={`Move source ${index + 1} up`}
                className={ROW_BUTTON}
              >
                Up
              </button>
              <button
                type="button"
                onClick={() => move(index, 1)}
                disabled={index === rows.length - 1}
                aria-label={`Move source ${index + 1} down`}
                className={ROW_BUTTON}
              >
                Down
              </button>
              <button
                type="button"
                onClick={() => remove(row.id)}
                aria-label={`Remove source ${index + 1}`}
                className={ROW_BUTTON}
              >
                Remove
              </button>
            </div>
          );
        })}
        {rows.length === 0 ? (
          <p className="text-sm text-neutral-500">No download sources yet.</p>
        ) : null}
      </div>

      <button
        type="button"
        onClick={add}
        disabled={rows.length >= MAX_DOWNLOADS}
        className="self-start rounded-md border border-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-40"
      >
        Add source
      </button>

      <form action={action} className="flex items-center gap-3">
        <input type="hidden" name="shortname" value={shortname} />
        <input type="hidden" name="downloads" value={toPayload(rows)} />
        <button
          type="submit"
          disabled={pending}
          className="self-start rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-60"
        >
          {pending ? "Saving…" : "Save downloads"}
        </button>
        {state ? (
          <p role={state.ok ? "status" : "alert"} className={`text-sm ${state.ok ? "text-neutral-300" : "text-red-400"}`}>
            {state.message}
          </p>
        ) : null}
      </form>
    </div>
  );
}

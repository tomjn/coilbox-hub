"use client";

import { useActionState, useState } from "react";
import { reportMap } from "@/app/moderation/actions";
import { MAP_REPORT_KINDS, type MapReportState } from "@/lib/maps/report";

/** The report control for a map page (#294): a button that opens the form, and
 *  the form. The same shape as `ReportButton`, which reports a gallery item,
 *  with the kind of problem chosen from a short list beside the free text. */
export function MapReportButton({ mapId }: { mapId: string }) {
  const [open, setOpen] = useState(false);

  if (!open) {
    return (
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="self-start text-xs text-neutral-400 transition-colors hover:text-neutral-200 active:text-neutral-200"
      >
        Report a problem with this map
      </button>
    );
  }

  return <MapReportForm mapId={mapId} onCancel={() => setOpen(false)} />;
}

export function MapReportForm({ mapId, onCancel }: { mapId: string; onCancel: () => void }) {
  const [state, formAction, pending] = useActionState<MapReportState | null, FormData>(
    reportMap,
    null,
  );

  if (state?.ok) {
    return (
      <p role="status" className="text-xs text-neutral-400">
        {state.message}
      </p>
    );
  }

  return (
    <form
      action={formAction}
      className="flex flex-col gap-3 rounded-md border border-neutral-800 bg-black p-3"
    >
      <input type="hidden" name="map_id" value={mapId} />
      <fieldset className="flex flex-col gap-1.5">
        <legend className="mb-1 text-xs text-neutral-400">
          What is wrong with it? No account needed.
        </legend>
        {MAP_REPORT_KINDS.map((kind) => (
          <label key={kind.value} className="flex items-center gap-2 py-0.5 text-sm text-neutral-200">
            <input type="radio" name="kind" value={kind.value} required />
            {kind.label}
          </label>
        ))}
      </fieldset>
      <div className="flex flex-col gap-1.5">
        <label className="text-xs text-neutral-400" htmlFor="map-report-reason">
          Tell us more
        </label>
        <textarea
          id="map-report-reason"
          name="reason"
          rows={3}
          required
          maxLength={1000}
          className="w-full rounded border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400"
        />
      </div>
      {state ? (
        <p role="alert" className="text-sm text-red-400">
          {state.message}
        </p>
      ) : null}
      <div className="flex gap-2">
        <button
          type="submit"
          disabled={pending}
          className="rounded border border-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-60"
        >
          {pending ? "Sending…" : "Send"}
        </button>
        <button
          type="button"
          onClick={onCancel}
          className="px-3 py-1.5 text-xs text-neutral-400 hover:text-neutral-200 active:text-neutral-200"
        >
          Cancel
        </button>
      </div>
    </form>
  );
}

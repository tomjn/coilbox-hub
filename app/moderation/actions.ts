"use server";

import { revalidatePath, updateTag } from "next/cache";
import { TAGS } from "@/lib/cache/tags";
import {
  isMapReportKind,
  MAP_REPORT_MESSAGES,
  type MapReportState,
} from "@/lib/maps/report";
import { createClient } from "@/lib/supabase/server";

/** Reporting needs no account, because the person best placed to notice
 * something wrong is usually just browsing. */
export async function report(form: FormData): Promise<void> {
  const reason = String(form.get("reason") ?? "").trim();
  if (reason === "") return;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  await supabase.from("report").insert({
    item_id: String(form.get("item_id") ?? ""),
    reason: reason.slice(0, 1000),
    reporter_id: user?.id ?? null,
  });

  updateTag(TAGS.item(String(form.get("item_id") ?? "")));
}

/** Reporting a map (#294). Anyone can, for the reason `report` gives. The
 * database drops a second report on a map that already has an open one without
 * saying so, so the form reads the same whether this one was kept or not. */
export async function reportMap(
  _previous: MapReportState | null,
  form: FormData,
): Promise<MapReportState> {
  const mapId = form.get("map_id");
  if (typeof mapId !== "string" || mapId === "") {
    return { ok: false, message: MAP_REPORT_MESSAGES.notSent };
  }
  const kind = form.get("kind");
  if (!isMapReportKind(kind)) {
    return { ok: false, message: MAP_REPORT_MESSAGES.noKind };
  }
  const reason = String(form.get("reason") ?? "").trim();
  if (reason === "") return { ok: false, message: MAP_REPORT_MESSAGES.noReason };

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  const { error } = await supabase.from("report").insert({
    map_id: mapId,
    kind,
    reason: reason.slice(0, 1000),
    reporter_id: user?.id ?? null,
  });
  if (error) return { ok: false, message: MAP_REPORT_MESSAGES.notSaved };

  return { ok: true, message: MAP_REPORT_MESSAGES.sent };
}

/** Withdrawing and marking handled are one action. Leaving a report open after
 * acting on it just means reading it again later. */
export async function actOnReport(form: FormData): Promise<void> {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) return;

  const id = String(form.get("report_id") ?? "");
  const itemId = String(form.get("item_id") ?? "");

  // A map report carries no item. Marking it handled is all there is to do.
  if (itemId !== "" && form.get("withdraw") === "true") {
    await supabase
      .from("item")
      .update({ deleted_at: new Date().toISOString() })
      .eq("id", itemId);
  }

  await supabase
    .from("report")
    .update({ handled_at: new Date().toISOString(), handled_by: user.id })
    .eq("id", id);

  // The queue is read per request, so it only needs the router cache clearing.
  // A withdrawn item is held, and every listing it was on is too.
  revalidatePath("/moderation");
  if (itemId !== "") {
    updateTag(TAGS.item(itemId));
    updateTag(TAGS.items);
  }
}

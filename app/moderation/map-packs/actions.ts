"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { isUuid } from "@/lib/assets/queue";
import type { MapFormState } from "@/lib/maps/featured";
import {
  addedMapsMessage,
  MAP_PACK_MESSAGES,
  parsePastedMapNames,
  readPackText,
} from "@/lib/maps/packs";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";

/**
 * The writes the map pack moderation pages make (tomjn/coilbox#3206).
 *
 * The same split as `setMapFeatured` (`app/moderation/maps/actions.ts`): every
 * action is reachable as a plain POST, so each asks `is_moderator()` with the
 * caller's own session, then writes with the secret key. `authenticated` holds
 * no write on either pack table.
 *
 * No cache tag is named. `GET /api/v1/map-packs` reads the database on every
 * request, the same as `/api/v1/games`, so only the two moderation pages need
 * revalidating.
 */

type Moderator = { ok: true; id: string } | { ok: false; state: MapFormState };

async function moderator(): Promise<Moderator> {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) return { ok: false, state: { ok: false, message: MAP_PACK_MESSAGES.signedOut } };

  const { data: allowed } = await supabase.rpc("is_moderator");
  if (allowed !== true) return { ok: false, state: { ok: false, message: MAP_PACK_MESSAGES.notAllowed } };

  return { ok: true, id: user.id };
}

function revalidatePack(id: string): void {
  revalidatePath("/moderation/map-packs");
  revalidatePath(`/moderation/map-packs/${id}`);
}

/** Start a pack with a title, then go to its page to fill it. */
export async function createMapPack(
  _previous: MapFormState | null,
  form: FormData,
): Promise<MapFormState> {
  const text = readPackText(String(form.get("title") ?? ""), String(form.get("blurb") ?? ""));
  if (!text) return { ok: false, message: MAP_PACK_MESSAGES.badText };

  const who = await moderator();
  if (!who.ok) return who.state;

  const { data, error } = await createAdminClient()
    .from("map_pack")
    .insert({ title: text.title, blurb: text.blurb })
    .select("id")
    .single();

  if (error || !data) {
    console.error("createMapPack: the pack was not created", error);
    return { ok: false, message: MAP_PACK_MESSAGES.notSaved };
  }

  revalidatePath("/moderation/map-packs");
  // Outside any try, because redirect works by throwing.
  redirect(`/moderation/map-packs/${(data as { id: string }).id}`);
}

/** Change a pack's title and blurb. The whole of both fields is the answer. */
export async function saveMapPack(
  _previous: MapFormState | null,
  form: FormData,
): Promise<MapFormState> {
  const id = String(form.get("id") ?? "").trim();
  if (!isUuid(id)) return { ok: false, message: MAP_PACK_MESSAGES.notSent };

  const text = readPackText(String(form.get("title") ?? ""), String(form.get("blurb") ?? ""));
  if (!text) return { ok: false, message: MAP_PACK_MESSAGES.badText };

  const who = await moderator();
  if (!who.ok) return who.state;

  const { data, error } = await createAdminClient()
    .from("map_pack")
    .update({ title: text.title, blurb: text.blurb })
    .eq("id", id)
    .select("id");

  if (error) {
    console.error(`saveMapPack: ${id} was not updated`, error);
    return { ok: false, message: MAP_PACK_MESSAGES.notSaved };
  }
  if (!data || data.length === 0) return { ok: false, message: MAP_PACK_MESSAGES.notFound };

  revalidatePack(id);
  return { ok: true, message: MAP_PACK_MESSAGES.saved };
}

/** Put a pack in coilbox's Map packs menu, or take it out. */
export async function setMapPackFeatured(
  _previous: MapFormState | null,
  form: FormData,
): Promise<MapFormState> {
  const id = String(form.get("id") ?? "").trim();
  const featured = form.get("featured") === "true";
  if (!isUuid(id)) return { ok: false, message: MAP_PACK_MESSAGES.notSent };

  const who = await moderator();
  if (!who.ok) return who.state;

  // Both columns ride one update, and unfeaturing clears both, the same as
  // setMapFeatured.
  const { data, error } = await createAdminClient()
    .from("map_pack")
    .update(
      featured
        ? { featured_at: new Date().toISOString(), featured_by: who.id }
        : { featured_at: null, featured_by: null },
    )
    .eq("id", id)
    .select("id");

  if (error) {
    console.error(`setMapPackFeatured: ${id} was not updated`, error);
    return { ok: false, message: MAP_PACK_MESSAGES.notSaved };
  }
  if (!data || data.length === 0) return { ok: false, message: MAP_PACK_MESSAGES.notFound };

  revalidatePack(id);
  return {
    ok: true,
    message: featured ? MAP_PACK_MESSAGES.featured : MAP_PACK_MESSAGES.unfeatured,
  };
}

/**
 * Add a pasted list of map names or archive filenames to a pack.
 *
 * `public.add_maps_to_pack` does the matching, so hundreds of lines travel in
 * one request body. The answer names every line that matched nothing.
 */
export async function addMapsToPack(
  _previous: MapFormState | null,
  form: FormData,
): Promise<MapFormState> {
  const id = String(form.get("id") ?? "").trim();
  if (!isUuid(id)) return { ok: false, message: MAP_PACK_MESSAGES.notSent };

  const names = parsePastedMapNames(String(form.get("names") ?? ""));
  if (names.length === 0) return { ok: false, message: MAP_PACK_MESSAGES.nothingPasted };

  const who = await moderator();
  if (!who.ok) return who.state;

  const { data, error } = await createAdminClient().rpc("add_maps_to_pack", {
    p_pack_id: id,
    p_names: names,
  });

  if (error) {
    // 23503 is the foreign key: the pack was deleted under the form.
    if (error.code === "23503") return { ok: false, message: MAP_PACK_MESSAGES.notFound };
    console.error(`addMapsToPack: nothing was added to ${id}`, error);
    return { ok: false, message: MAP_PACK_MESSAGES.notSaved };
  }

  revalidatePack(id);
  return {
    ok: true,
    message: addedMapsMessage((data ?? []) as { wanted: string; map_name: string | null }[]),
  };
}

/** Take one map out of a pack. */
export async function removeMapFromPack(
  _previous: MapFormState | null,
  form: FormData,
): Promise<MapFormState> {
  const id = String(form.get("id") ?? "").trim();
  const mapName = String(form.get("map") ?? "");
  if (!isUuid(id) || mapName === "") return { ok: false, message: MAP_PACK_MESSAGES.notSent };

  const who = await moderator();
  if (!who.ok) return who.state;

  const { error } = await createAdminClient()
    .from("map_pack_map")
    .delete()
    .eq("pack_id", id)
    .eq("map_name", mapName);

  if (error) {
    console.error(`removeMapFromPack: ${mapName} was not removed from ${id}`, error);
    return { ok: false, message: MAP_PACK_MESSAGES.notSaved };
  }

  revalidatePack(id);
  return { ok: true, message: MAP_PACK_MESSAGES.removed };
}

/** Throw a pack away, maps and all, then go back to the list. */
export async function deleteMapPack(
  _previous: MapFormState | null,
  form: FormData,
): Promise<MapFormState> {
  const id = String(form.get("id") ?? "").trim();
  if (!isUuid(id)) return { ok: false, message: MAP_PACK_MESSAGES.notSent };

  const who = await moderator();
  if (!who.ok) return who.state;

  const { error } = await createAdminClient().from("map_pack").delete().eq("id", id);
  if (error) {
    console.error(`deleteMapPack: ${id} was not deleted`, error);
    return { ok: false, message: MAP_PACK_MESSAGES.notSaved };
  }

  revalidatePath("/moderation/map-packs");
  redirect("/moderation/map-packs");
}

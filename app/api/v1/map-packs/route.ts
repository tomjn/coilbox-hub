import { corsPreflight } from "@/lib/api/cors";
import { buildMapPackListBody } from "@/lib/api/mapPackList";
import { apiError, apiJson } from "@/lib/api/response";
import { fetchMapPacks } from "@/lib/maps/packs";
import { createAnonClient } from "@/lib/supabase/anon";

/**
 * Every map pack the hub holds, for coilbox's Map packs menu
 * (tomjn/coilbox#3206).
 *
 * Anonymous and read through the anonymous client, the same as
 * `/api/v1/games`. A failed read is a 503 rather than an empty list for the
 * reason that route gives: an empty list is a claim that there are no packs.
 * Coilbox reads either as "no hub packs today", so the difference is for anyone
 * else reading the route.
 */
export const OPTIONS = corsPreflight;

export async function GET() {
  const { packs, error } = await fetchMapPacks(createAnonClient());
  if (error) {
    console.error("GET /api/v1/map-packs: the packs could not be read", error);
    return apiError("The map packs could not be read just now.", 503);
  }
  return apiJson(buildMapPackListBody(packs));
}

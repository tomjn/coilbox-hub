import type { ImageResponse } from "next/og";

/**
 * A social preview card, as the `opengraph-image` routes hand it over.
 *
 * Each route draws its PNG inside a `"use cache"` function, so a repeat request
 * reads the bytes back instead of drawing again, and an edit clears them by tag.
 * The CDN keeps the response for the built-in `hours` cache profile: an hour
 * fresh, then up to a day served stale while one request fetches a new copy. A
 * copy already on the CDN outlives an edit by up to that hour.
 */
const CARD_CACHE_CONTROL = "public, s-maxage=3600, stale-while-revalidate=86400";

/** The drawn card as bytes, which a cached function can return. */
export async function cardBytes(card: ImageResponse): Promise<Uint8Array<ArrayBuffer>> {
  return new Uint8Array(await card.arrayBuffer());
}

export function cardResponse(png: Uint8Array<ArrayBuffer>): Response {
  return new Response(png, {
    headers: { "Content-Type": "image/png", "Cache-Control": CARD_CACHE_CONTROL },
  });
}

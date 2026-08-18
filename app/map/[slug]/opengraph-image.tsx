import { ImageResponse } from "next/og";
import { mapSizeLabel, mapTitle, playerCountLabel } from "@/lib/maps/labels";
import { loadMapPage } from "@/lib/maps/page";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";

/**
 * The preview a map link gets when it is pasted into Discord, which is where
 * most people will meet one. Built the same way as `app/item/[id]`'s card and
 * for the same reason: a generic image on every map wastes the only chance the
 * link has to say what it is.
 *
 * The card is the map's own facts and no picture of it. The hub holds a minimap
 * for many maps and drawing one here would be its own bytes rather than a
 * request to somebody else's server, which is what the item card refused. It is
 * left for a later change all the same, because a card that shows a picture for
 * some maps and not others is a decision about how the missing half should look
 * rather than a line of markup.
 *
 * It reads through the same loader the page does, so the licence gate applies
 * here too. A map the hub may not publish has no page and no card either, and a
 * takedown that left a card publishing the facts would be a takedown with a hole
 * in it.
 */
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";
export const alt = "A map on Coilbox Hub";

export default async function Image({ params }: { params: Promise<{ slug: string }> }) {
  // Awaited, not read straight off. `params` is a promise here, and taking
  // `.slug` from the promise gives every map the same generic card.
  const { slug } = await params;
  const page = await loadMapPage(await createClient(), createAdminClient(), slug);

  const title = page ? mapTitle(page.map) : "Coilbox Hub";
  const facts = page
    ? [
        mapSizeLabel(page.map.width_elmos, page.map.height_elmos),
        playerCountLabel(page.spots.start.length),
      ]
        .filter(Boolean)
        .join("  ·  ")
    : "";
  const credit = page?.authors.map((author) => author.name).join(", ");

  const TITLE_LIMIT = 80;
  const shown = title.length > TITLE_LIMIT ? `${title.slice(0, TITLE_LIMIT)}…` : title;

  return new ImageResponse(
    (
      <div
        style={{
          width: "100%",
          height: "100%",
          display: "flex",
          background: "#0b0b0d",
          color: "#fafafa",
        }}
      >
        <div
          style={{
            flex: 1,
            minWidth: 0,
            display: "flex",
            flexDirection: "column",
            justifyContent: "space-between",
            padding: 72,
          }}
        >
          <div style={{ display: "flex", alignItems: "center", gap: 20 }}>
            <svg
              width={48}
              height={48}
              viewBox="0 0 24 24"
              fill="none"
              stroke="#fafafa"
              strokeWidth={2}
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M12 3 18.62 7.39A1.3 1.3 0 0 1 19.2 8.56L18.76 15.13A1.3 1.3 0 0 1 18.03 16.21L12.68 18.79A1.3 1.3 0 0 1 11.38 18.69L6.99 15.68A1.3 1.3 0 0 1 6.44 14.5L6.84 9.82A1.3 1.3 0 0 1 7.6 8.75L11.31 7.06A1.3 1.3 0 0 1 12.61 7.2L15.39 9.24A1.3 1.3 0 0 1 15.91 10.45L15.56 13.24A1.3 1.3 0 0 1 14.75 14.28L12.72 15.09A1.3 1.3 0 0 1 11.4 14.88L10.22 13.89A1.3 1.3 0 0 1 9.79 12.61L10.04 11.47A1.09 1.09 0 0 1 10.66 10.71L11.34 10.4" />
            </svg>
            <div style={{ fontSize: 28, color: "#8a8a8a" }}>Map</div>
          </div>

          <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
            <div style={{ fontSize: 68, fontWeight: 600, lineHeight: 1.1 }}>{shown}</div>
            {facts ? <div style={{ fontSize: 30, color: "#a3a3a3" }}>{facts}</div> : null}
          </div>

          <div style={{ display: "flex", fontSize: 26, color: "#6b6b6b" }}>
            {credit ? `by ${credit}` : "Coilbox Hub"}
          </div>
        </div>
      </div>
    ),
    size,
  );
}

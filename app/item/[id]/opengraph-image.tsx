import { ImageResponse } from "next/og";
import { findBarMap } from "@/lib/bar/maps";
import { previewAsJpeg } from "@/lib/bar/previewUrl";
import { itemLabel } from "@/lib/gallery/label";
import { createClient } from "@/lib/supabase/server";

/**
 * The preview a link gets when it is pasted into Discord, which is how most
 * people will meet an item. This is the reason the frontend is server rendered
 * rather than a static bundle: a generic image on every item wastes the only
 * chance the link has to say what it is.
 *
 * An item on a map BAR lists gets that map down the right hand side, so the
 * card is recognisable before a word of it is read. No start boxes here: the
 * panel is a thumbnail in a feed, not something anybody studies.
 */
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";
export const alt = "An item on Coilbox Hub";

/** Wide enough for the panel at retina, small enough to keep card generation
 * quick. */
const PANEL_SIZE = 512;
const PANEL_WIDTH = 420;

const ONE_DAY = 86400;

/**
 * The map's thumbnail as bytes Satori can read, or nothing.
 *
 * Inlined rather than left as a URL for Satori to fetch, because a fetch that
 * fails inside image generation takes the whole card down with it, and a card
 * with no picture is far better than a link with no card. Jpeg because Satori
 * decodes png and jpeg only, and BAR serves webp by default.
 */
async function panelImage(preview: string): Promise<string | null> {
  const url = previewAsJpeg(preview, PANEL_SIZE);
  if (!url) return null;
  try {
    const res = await fetch(url, {
      next: { revalidate: ONE_DAY },
      signal: AbortSignal.timeout(5000),
    });
    if (!res.ok) return null;
    const bytes = Buffer.from(await res.arrayBuffer());
    return `data:image/jpeg;base64,${bytes.toString("base64")}`;
  } catch {
    return null;
  }
}

export default async function Image({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  // Awaited, not read straight off. `params` is a promise here, and taking
  // `.id` from the promise gave every item the same generic card.
  const { id } = await params;
  const supabase = await createClient();
  const { data } = await supabase
    .from("item")
    .select("kind,mode,title,game_name,map_name,author_name")
    .eq("id", id)
    .maybeSingle();

  const label = data ? itemLabel(data.kind, data.mode) : "";
  const facts = [data?.game_name, data?.map_name].filter(Boolean).join("  ·  ");

  const barMap = await findBarMap(data?.map_name ?? null);
  const panel = barMap?.images?.preview
    ? await panelImage(barMap.images.preview)
    : null;

  // The panel takes a third of the width, so the title gets less room and has
  // to be set smaller and cut sooner to stay inside the card.
  const titleSize = panel ? 56 : 68;
  const titleLimit = panel ? 60 : 80;
  const raw = data?.title ?? "Coilbox Hub";
  const title = raw.length > titleLimit ? `${raw.slice(0, titleLimit)}…` : raw;

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
            <div style={{ fontSize: 28, color: "#8a8a8a" }}>{label}</div>
          </div>

          <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
            <div style={{ fontSize: titleSize, fontWeight: 600, lineHeight: 1.1 }}>
              {title}
            </div>
            {facts ? (
              <div style={{ fontSize: 30, color: "#a3a3a3" }}>{facts}</div>
            ) : null}
          </div>

          <div style={{ display: "flex", fontSize: 26, color: "#6b6b6b" }}>
            {data?.author_name ? `by ${data.author_name}` : "Coilbox Hub"}
          </div>
          </div>
        {panel ? (
          <div
            style={{
              display: "flex",
              width: PANEL_WIDTH,
              height: "100%",
              padding: 48,
              paddingLeft: 0,
              alignItems: "center",
            }}
          >
            {/* Fitted, not cropped. A card this shape crops a wide map down to
                its middle, and the outline of a map is most of what makes it
                recognisable at a glance in a feed. */}
            <img
              src={panel}
              alt=""
              width={PANEL_WIDTH - 48}
              height={size.height - 96}
              style={{ objectFit: "contain" }}
            />
          </div>
        ) : null}
      </div>
    ),
    size,
  );
}

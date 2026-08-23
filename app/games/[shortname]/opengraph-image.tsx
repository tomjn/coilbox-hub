import { ImageResponse } from "next/og";
import { staticTierUrl } from "@/lib/assets/cdn";
import { gameCountLabel, gameTitle } from "@/lib/games/labels";
import { gamePageCached } from "@/lib/games/cached";

/**
 * The preview a game link gets when it is pasted into Discord (#240), built the
 * same way as the map card and for the same reason: a generic image on every
 * game wastes the only chance the link has to say what it is.
 *
 * It reads through the same cached loader the page does, so a hidden or unknown
 * shortname falls back to the generic hub card rather than advertising a game
 * the site does not show.
 *
 * The logo rides along when the hub holds one and the durable tier answers;
 * anything else renders as text alone, which is how the faction strip already
 * treats a missing picture.
 */
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";
export const alt = "A game on Coilbox Hub";

async function logoDataUri(path: string): Promise<string | null> {
  try {
    const response = await fetch(staticTierUrl(path));
    if (!response.ok) return null;
    const buffer = await response.arrayBuffer();
    const type = response.headers.get("content-type") ?? "image/png";
    return `data:${type};base64,${Buffer.from(buffer).toString("base64")}`;
  } catch {
    return null;
  }
}

export default async function Image({
  params,
}: {
  params: Promise<{ shortname: string }>;
}) {
  const { shortname } = await params;
  const page = await gamePageCached(shortname);

  const title = page ? gameTitle(page) : "Coilbox Hub";
  const measures = page ? gameCountLabel(page) : "";
  const logo =
    page?.logo_path ? await logoDataUri(page.logo_path) : null;

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
            <div style={{ fontSize: 28, color: "#8a8a8a" }}>Game</div>
          </div>

          <div style={{ display: "flex", alignItems: "center", gap: 32 }}>
            {logo ? (
              <img src={logo} alt="" width={128} height={128} style={{ borderRadius: 16, objectFit: "contain" }} />
            ) : null}
            <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
              <div style={{ fontSize: 68, fontWeight: 600, lineHeight: 1.1 }}>{shown}</div>
              {measures ? <div style={{ fontSize: 30, color: "#a3a3a3" }}>{measures}</div> : null}
            </div>
          </div>

          <div style={{ display: "flex", fontSize: 26, color: "#6b6b6b" }}>Coilbox Hub</div>
        </div>
      </div>
    ),
    size,
  );
}

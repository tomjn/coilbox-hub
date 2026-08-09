import { ImageResponse } from "next/og";

/**
 * The link preview Discord and every other unfurler shows. This exists on the
 * placeholder deliberately: per-item link previews are the reason the frontend
 * is server rendered rather than a static bundle, so the mechanism is proved
 * before anything depends on it.
 */
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";
export const alt = "Coilbox Hub";

export default function Image() {
  return new ImageResponse(
    (
      <div
        style={{
          width: "100%",
          height: "100%",
          display: "flex",
          flexDirection: "column",
          alignItems: "center",
          justifyContent: "center",
          gap: 32,
          background: "#0b0b0d",
          color: "#fafafa",
        }}
      >
        <svg
          width={160}
          height={160}
          viewBox="0 0 24 24"
          fill="none"
          stroke="#fafafa"
          strokeWidth={2}
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M12 3 18.62 7.39A1.3 1.3 0 0 1 19.2 8.56L18.76 15.13A1.3 1.3 0 0 1 18.03 16.21L12.68 18.79A1.3 1.3 0 0 1 11.38 18.69L6.99 15.68A1.3 1.3 0 0 1 6.44 14.5L6.84 9.82A1.3 1.3 0 0 1 7.6 8.75L11.31 7.06A1.3 1.3 0 0 1 12.61 7.2L15.39 9.24A1.3 1.3 0 0 1 15.91 10.45L15.56 13.24A1.3 1.3 0 0 1 14.75 14.28L12.72 15.09A1.3 1.3 0 0 1 11.4 14.88L10.22 13.89A1.3 1.3 0 0 1 9.79 12.61L10.04 11.47A1.09 1.09 0 0 1 10.66 10.71L11.34 10.4" />
        </svg>
        <div style={{ fontSize: 64, fontWeight: 600 }}>Coilbox Hub</div>
      </div>
    ),
    size,
  );
}

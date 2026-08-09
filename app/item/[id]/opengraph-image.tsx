import { ImageResponse } from "next/og";
import { itemLabel } from "@/lib/gallery/label";
import { createClient } from "@/lib/supabase/server";

/**
 * The preview a link gets when it is pasted into Discord, which is how most
 * people will meet an item. This is the reason the frontend is server rendered
 * rather than a static bundle: a generic image on every item wastes the only
 * chance the link has to say what it is.
 */
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";
export const alt = "An item on Coilbox Hub";

export default async function Image({
  params,
}: {
  params: { id: string };
}) {
  const supabase = await createClient();
  const { data } = await supabase
    .from("item")
    .select("kind,mode,title,game_name,map_name,author_name")
    .eq("id", params.id)
    .maybeSingle();

  const title = data?.title ?? "Coilbox Hub";
  const label = data ? itemLabel(data.kind, data.mode) : "";
  const facts = [data?.game_name, data?.map_name].filter(Boolean).join("  ·  ");

  return new ImageResponse(
    (
      <div
        style={{
          width: "100%",
          height: "100%",
          display: "flex",
          flexDirection: "column",
          justifyContent: "space-between",
          padding: 72,
          background: "#0b0b0d",
          color: "#fafafa",
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
          <div style={{ fontSize: 68, fontWeight: 600, lineHeight: 1.1 }}>
            {title.length > 80 ? `${title.slice(0, 80)}…` : title}
          </div>
          {facts ? (
            <div style={{ fontSize: 30, color: "#a3a3a3" }}>{facts}</div>
          ) : null}
        </div>

        <div style={{ display: "flex", fontSize: 26, color: "#6b6b6b" }}>
          {data?.author_name ? `by ${data.author_name}` : "Coilbox Hub"}
        </div>
      </div>
    ),
    size,
  );
}

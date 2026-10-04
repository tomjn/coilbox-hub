import { feedXml } from "@/lib/feed/cached";
import { parseFeedKind } from "@/lib/feed/gallery";

/**
 * The newest gallery items as Atom, for a feed reader or a chat channel.
 *
 * Reading the query string makes this route render per request. What it renders
 * is cheap: `feedXml` holds the document, one per kind, and the `items` tag
 * clears them.
 */
export async function GET(request: Request) {
  const kind = parseFeedKind(new URL(request.url).searchParams.get("kind"));
  if (!kind.ok) {
    return new Response(kind.error, {
      status: 400,
      headers: { "Content-Type": "text/plain; charset=utf-8" },
    });
  }

  return new Response(await feedXml(kind.kind), {
    headers: { "Content-Type": "application/atom+xml; charset=utf-8" },
  });
}

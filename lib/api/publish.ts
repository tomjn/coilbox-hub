import type { PublishFields, PublishOutcome } from "@/lib/gallery/publish";

const BODY_KEYS = ["code", "title", "description", "tags"] as const;

export type ParsedPublishBody =
  | { ok: true; fields: PublishFields }
  | { ok: false; error: string };

/**
 * Same strictness as `parseApiFilters` in `lib/api/items.ts`, and for the
 * same reason: a client that sent `Tags` instead of `tags` and had it
 * silently ignored is worse served than one told the field name is wrong.
 * Everything past this point - what makes a code publishable, what a title
 * has to look like - is `publishItem`'s job, shared with the form. This only
 * checks that the JSON has the right shape to reach it.
 */
export function parsePublishBody(body: unknown): ParsedPublishBody {
  if (typeof body !== "object" || body === null || Array.isArray(body)) {
    return { ok: false, error: "The request body must be a JSON object." };
  }
  const record = body as Record<string, unknown>;

  for (const key of Object.keys(record)) {
    if (!(BODY_KEYS as readonly string[]).includes(key)) {
      return { ok: false, error: `Unknown field: ${key}` };
    }
  }

  const code = record.code;
  if (typeof code !== "string" || code.trim() === "") {
    return { ok: false, error: "`code` is required and must be a string." };
  }

  const title = record.title;
  if (typeof title !== "string") {
    return { ok: false, error: "`title` is required and must be a string." };
  }

  const description = record.description ?? "";
  if (typeof description !== "string") {
    return { ok: false, error: "`description` must be a string." };
  }

  const tags = record.tags ?? [];
  if (!Array.isArray(tags) || !tags.every((tag) => typeof tag === "string")) {
    return { ok: false, error: "`tags` must be an array of strings." };
  }

  return { ok: true, fields: { code, title, description, tags } };
}

/**
 * Turns a failed `publishItem` outcome into a status code. 422 for anything
 * `accept()` or the title check rejected: the request was well formed JSON,
 * but what it described cannot be published, the same shape as a form
 * submission that comes back with an error rather than a redirect. 429 for
 * the database rate limit (see lib/gallery/publish.ts), 500 for anything
 * else the write itself failed on.
 */
export function statusForPublishFailure(
  status: Extract<PublishOutcome, { ok: false }>["status"],
): number {
  switch (status) {
    case "invalid":
      return 422;
    case "rate_limited":
      return 429;
    case "storage_error":
      return 500;
  }
}

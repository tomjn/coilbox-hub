import { handleUpload, type HandleUploadBody } from "@vercel/blob/client";
import { NextResponse } from "next/server";
import { parseAssetUpload } from "@/lib/api/assetUpload";
import { corsPreflight, withCors } from "@/lib/api/cors";
import { apiError } from "@/lib/api/response";
import { BLOB_TOKEN_ERROR, requireBlobToken } from "@/lib/assets/blob";
import { ASSET_MAX_OBJECT_BYTES, checkAssetUpload } from "@/lib/assets/upload";
import { createAdminClient } from "@/lib/supabase/admin";
import { authenticateBearer } from "@/lib/supabase/bearer";
import { SUPABASE_SERVICE_ROLE_ERROR } from "@/lib/supabase/config";

/**
 * The client direct upload path (issue #104), for the website.
 *
 * A browser has a supported SDK for this and a desktop client does not, which
 * is the whole of why there are two paths. `upload()` from `@vercel/blob/client`
 * asks this route for a token, uploads straight to Blob with it, and never
 * moves the bytes through a function.
 *
 * Two mechanisms is fine. Two sets of checks would not be, so there is exactly
 * one: `checkAssetUpload`, the same call `../route.ts` makes, against the same
 * declaration shape, producing the same status codes and the same messages.
 * The only thing this route adds is that the token it hands out is bound to the
 * path the hub derived, so the client cannot upload to a path of its choosing.
 *
 * ## Auth
 *
 * The same bearer token as everything else under `/api/v1`, sent through
 * `upload({ headers })`. The wildcard CORS origin cannot carry credentials, so
 * a cookie would not reach here from a desktop webview even though the website
 * has one.
 *
 * ## What this route does not do
 *
 * It does not write a row, and it does not accept Blob's upload completed
 * callback. The callback does not fire against localhost at all, so a row
 * written only from there would be a code path nobody can exercise in
 * development and would be discovered broken in production. #106's confirm
 * route is the primary path and writes the row for this half of the pipeline.
 * Until it lands, an upload through here leaves an object with no row, which is
 * unreachable because nothing hands out its URL, and #113 clears orphans.
 */
export const dynamic = "force-dynamic";

export const OPTIONS = corsPreflight;

type TokenRequest = Extract<HandleUploadBody, { type: "blob.generate-client-token" }>;

function isTokenRequest(value: unknown): value is TokenRequest {
  return (
    typeof value === "object" &&
    value !== null &&
    (value as { type?: unknown }).type === "blob.generate-client-token"
  );
}

function isCompletionCallback(value: unknown): boolean {
  return (
    typeof value === "object" &&
    value !== null &&
    (value as { type?: unknown }).type === "blob.upload-completed"
  );
}

export async function POST(request: Request) {
  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return apiError("The request body must be JSON.", 400);
  }

  if (isCompletionCallback(body)) {
    return apiError(
      "This hub does not take upload completed callbacks. The client confirms its own upload.",
      501,
    );
  }

  if (!isTokenRequest(body)) {
    return apiError(
      'The request body must be the payload `upload()` sends, with a type of "blob.generate-client-token".',
      400,
    );
  }

  const auth = await authenticateBearer(request);
  if (!auth.ok) {
    return apiError(
      auth.reason === "missing"
        ? 'Send an access token as "Authorization: Bearer <token>".'
        : "That access token is not valid. Sign in again and use a fresh one.",
      401,
    );
  }

  // Every asset is under 2 MB, so a multipart upload is either a client that
  // set the flag for no reason or bytes that are about to fail the size cap.
  // Refusing it keeps one shape of upload to reason about.
  if (body.payload.multipart) {
    return apiError("Multipart uploads are not accepted. Every asset fits in one part.", 400);
  }

  let json: unknown;
  try {
    json = JSON.parse(body.payload.clientPayload ?? "");
  } catch {
    return apiError("`clientPayload` must be the asset declaration as JSON.", 400);
  }

  const parsed = parseAssetUpload(json);
  if (!parsed.ok) {
    return apiError(parsed.error, 400);
  }

  let admin;
  try {
    admin = createAdminClient();
  } catch {
    return apiError(SUPABASE_SERVICE_ROLE_ERROR, 503);
  }

  // Run before `handleUpload` rather than inside `onBeforeGenerateToken`, so
  // that a refusal keeps its own status code and message. The SDK turns a throw
  // in that callback into one generic failure, which would leave this path
  // answering differently to the other one for the same reason.
  const check = await checkAssetUpload(admin, auth.user.id, parsed.declaration);
  if (!check.ok) {
    return apiError(check.error, check.status);
  }

  // The client picks the pathname it asks for and the token is issued against
  // it, so this is where the hub's own derivation becomes binding rather than
  // advisory.
  if (body.payload.pathname !== check.path) {
    return apiError(`That declaration belongs at "${check.path}".`, 409);
  }

  let token: string;
  try {
    token = requireBlobToken();
  } catch {
    return apiError(BLOB_TOKEN_ERROR, 503);
  }

  const result = await handleUpload({
    token,
    request,
    body,
    // Everything is already decided. These are the constraints Blob itself
    // enforces on the upload the token permits, so a client that passes the
    // checks and then sends something else is refused by the store.
    onBeforeGenerateToken: async () => ({
      allowedContentTypes: [parsed.declaration.mime],
      maximumSizeInBytes: ASSET_MAX_OBJECT_BYTES,
      addRandomSuffix: false,
      allowOverwrite: true,
      tokenPayload: null,
    }),
  });

  return withCors(NextResponse.json(result, { headers: { "Cache-Control": "no-store" } }));
}

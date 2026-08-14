import { NextResponse } from "next/server";
import { buildAssetUploadBody, parseAssetUpload } from "@/lib/api/assetUpload";
import { corsPreflight, withCors } from "@/lib/api/cors";
import { apiError } from "@/lib/api/response";
import { BLOB_TOKEN_ERROR, deleteBlobAssets, putBlobAsset } from "@/lib/assets/blob";
import { checkAssetUpload, insertPendingAsset } from "@/lib/assets/upload";
import { createAdminClient } from "@/lib/supabase/admin";
import { authenticateBearer } from "@/lib/supabase/bearer";
import { SUPABASE_SERVICE_ROLE_ERROR } from "@/lib/supabase/config";

/**
 * The upload route (issue #104). Everything that uploads posts the bytes here
 * and this calls `put()`. There is no other way in.
 *
 * Not client direct, and the reasons are in the issue rather than a preference.
 * The `vercel_blob` Rust crate has one release last touched about three years
 * ago, which is not something to put a shipped desktop client on, and reverse
 * engineering Blob's HTTP contract out of the JS SDK works right up until
 * Vercel changes an unpublished interface and breaks builds already on people's
 * machines.
 *
 * The website used to upload client direct, which is the supported path in a
 * browser and the one that keeps the bytes out of a function. It was removed in
 * #133 because `upload()` hands the browser the finished URL, so the uploader
 * learned where its own unreviewed picture was and could publish it. Nothing
 * about the path scheme fixes that: the client does the PUT, so it has to be
 * told where to send the bytes. Routing everything through here is what makes
 * the hub the only party that ever sees the path, which is what the moderation
 * queue rests on.
 *
 * The 4.5 MB platform limit on a function body is not a constraint here. It is
 * free size enforcement that runs before any of this code does, and the assets
 * are 5 to 150 KB. Next puts no limit of its own on a route handler body, so
 * that platform limit is the only one. The bandwidth cost of every upload now
 * crossing a function is the same 5 to 150 KB, and the seed never comes through
 * here at all.
 *
 * ## The order, and why nothing writes before it finishes
 *
 * `put()` is the only advanced operation this codebase makes and a Hobby store
 * gets 2,000 a month, which cannot be topped up and cannot be paid through, so
 * a rejected upload has to cost zero. Everything below the auth check runs
 * before the write:
 *
 * 1. the bearer token verifies
 * 2. the body is multipart and carries both parts
 * 3. the declaration parses, with unknown fields refused
 * 4. the bytes received are the length the declaration claims
 * 5. `checkAssetUpload`: MIME, size, path, licence, identity, four quotas
 *
 * ## Two responses that are not the same failure
 *
 * A 409 means the hub already holds that identity and the caller should stop
 * asking. Replacing a row whose `source_hash` has changed is #106's, and until
 * it lands an existing identity is refused rather than overwritten.
 *
 * ## Why a 201 says so little
 *
 * It says the upload was accepted and is pending, and nothing about where the
 * bytes are. The store is public, so the path is the URL, and a caller that
 * held either could publish the picture before a reviewer had seen it, which is
 * the whole of what the queue exists to stop (#131). Withholding it from a
 * well behaved caller costs nothing: it already has the bytes it just sent, and
 * an approved row resolves through #108 like any other.
 */
export const dynamic = "force-dynamic";

export const OPTIONS = corsPreflight;

export async function POST(request: Request) {
  const auth = await authenticateBearer(request);
  if (!auth.ok) {
    return apiError(
      auth.reason === "missing"
        ? 'Send an access token as "Authorization: Bearer <token>".'
        : "That access token is not valid. Sign in again and use a fresh one.",
      401,
    );
  }

  // Multipart, because one request carries a JSON declaration and a binary
  // body and neither belongs inside the other. Base64 in JSON would inflate
  // every upload by a third against a platform body limit.
  let form: FormData;
  try {
    form = await request.formData();
  } catch {
    return apiError(
      'The request body must be multipart/form-data with an "asset" JSON part and a "file" part.',
      415,
    );
  }

  const file = form.get("file");
  if (!(file instanceof Blob)) {
    return apiError('The "file" part is required and must carry the encoded bytes.', 400);
  }

  const declared = form.get("asset");
  if (typeof declared !== "string") {
    return apiError('The "asset" part is required and must be the declaration as JSON.', 400);
  }

  let json: unknown;
  try {
    json = JSON.parse(declared);
  } catch {
    return apiError('The "asset" part must be JSON.', 400);
  }

  const parsed = parseAssetUpload(json);
  if (!parsed.ok) {
    return apiError(parsed.error, 400);
  }

  // The declared size is what every quota is measured against, so it has to be
  // the size that actually arrived. Free to check, and without it a client
  // could declare a byte and send two megabytes.
  if (file.size !== parsed.declaration.bytes) {
    return apiError(
      `The "file" part is ${file.size} bytes and the declaration says ${parsed.declaration.bytes}.`,
      400,
    );
  }

  // #105 goes here: read the real dimensions out of the image header, a few KB
  // with no full decode, and refuse anything whose class caps it misses. It
  // wants the bytes and no round trip, which is what puts it after the last
  // pure check and before the first database one.

  let admin;
  try {
    admin = createAdminClient();
  } catch {
    return apiError(SUPABASE_SERVICE_ROLE_ERROR, 503);
  }

  const check = await checkAssetUpload(admin, auth.user.id, parsed.declaration);
  if (!check.ok) {
    return apiError(check.error, check.status);
  }

  // The advanced operation. Nothing above it has written anything, and nothing
  // below it can refuse the upload.
  //
  // `check.path` is where the hub asked for the bytes and `stored` is where
  // they went, which is not the same string: Blob appends a suffix nobody can
  // derive, and that suffix is the only thing standing between a pending
  // upload and a public URL (#131). Everything after this point uses `stored`,
  // because the derived path addresses no object.
  let stored: string;
  try {
    stored = await putBlobAsset(check.path, await file.arrayBuffer(), parsed.declaration.mime);
  } catch (error) {
    if (error instanceof Error && error.message === BLOB_TOKEN_ERROR) {
      return apiError(BLOB_TOKEN_ERROR, 503);
    }
    return apiError("The asset store would not accept that upload just now.", 502);
  }

  // The row is written here rather than by a second call, because this request
  // already holds everything the row needs and a confirm step that can fail
  // separately is a way to leave an object nothing points at. With the client
  // direct path gone (#133) there is no upload the server does not see the
  // bytes of, so no confirm route has a row to write.
  //
  // Deleting on a failed insert is free, so the store does not keep an object
  // no row will ever name.
  if (!(await insertPendingAsset(admin, auth.user.id, parsed.declaration, stored))) {
    await deleteBlobAssets([stored]).catch(() => {});
    return apiError("The asset was uploaded but its record could not be written.", 503);
  }

  return withCors(
    NextResponse.json(buildAssetUploadBody(), {
      status: 201,
      headers: { "Cache-Control": "no-store" },
    }),
  );
}

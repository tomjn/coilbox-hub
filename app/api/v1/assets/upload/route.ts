import { NextResponse } from "next/server";
import { buildAssetUploadBody, parseAssetUpload } from "@/lib/api/assetUpload";
import { corsPreflight, withCors } from "@/lib/api/cors";
import { apiError } from "@/lib/api/response";
import { BLOB_TOKEN_ERROR, deleteBlobAssets, putBlobAsset } from "@/lib/assets/blob";
import { checkAssetImage } from "@/lib/assets/caps";
import { encodedHash } from "@/lib/assets/hash";
import { IMAGE_HEADER_BYTES } from "@/lib/assets/imageHeader";
import { recordUnclaimedObject } from "@/lib/assets/orphan";
import { recordSourceConflict } from "@/lib/assets/sourceConflict";
import { checkAssetUpload, writePendingAsset } from "@/lib/assets/upload";
import { clientIp, recordUploadIp } from "@/lib/assets/uploadIp";
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
 * 5. `checkAssetImage`: the image header, against the caps for its class
 * 6. `encodedHash`: the hash of the bytes, which is where they will land
 * 7. `checkAssetUpload`: MIME, size, path, licence, identity, four quotas
 *
 * ## 201 created, 200 replaced
 *
 * An identity the hub already holds is not automatically a refusal any more
 * (#106). A newer archive with a different `source_hash` replaces the row it
 * already has, in place and back to pending, and that answers 200 because
 * nothing was created. Everything above still runs on it, unchanged and in the
 * same order, so a replacement is held to every rule a first upload is.
 *
 * The 409s that remain all mean stop asking rather than try again: the identity
 * belongs to another account, or it was rejected, or it already holds the same
 * `source_hash`.
 *
 * ## Why either of them says so little
 *
 * They say the upload was accepted and is pending, and nothing about where the
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

  // Every byte, once, and everything below reads this rather than the Blob
  // again. The whole body is already in memory by the time `formData()`
  // returns, so this is not a second copy of anything.
  const bytes = await file.arrayBuffer();

  // The real dimensions, out of the image header rather than out of the
  // declaration (#105). A few KB, no decode, no round trip, and the last thing
  // that can refuse the upload for free. `image.width` and `image.height` are
  // what reach the row: the declaration no longer carries a pair at all.
  const image = checkAssetImage(
    parsed.declaration.identity.variant,
    parsed.declaration.mime,
    new Uint8Array(bytes, 0, Math.min(IMAGE_HEADER_BYTES, bytes.byteLength)),
  );
  if (!image.ok) {
    return apiError(image.error, image.status);
  }

  // Where the bytes will land, out of the bytes (#154). It was the client's to
  // declare until now, and `hash` is the whole leaf of a map path, so declaring
  // somebody else's put your picture over theirs the moment promotion (#111)
  // committed it to a permanent public history. Costs no round trip and no
  // advanced operation, so it sits here with the free refusals rather than
  // after the write, and there is nothing left to refuse: a hash the hub
  // computed is always a hash the hub can spell as a path.
  //
  // `source_hash` is not this and cannot be. It is over the raw archive, which
  // never reaches the hub, so it stays the client's word. It names no object.
  const hash = await encodedHash(bytes);

  let admin;
  try {
    admin = createAdminClient();
  } catch {
    return apiError(SUPABASE_SERVICE_ROLE_ERROR, 503);
  }

  const check = await checkAssetUpload(admin, auth.user.id, parsed.declaration, hash);

  // Before the answer either way, because the 409 below is where #116's case
  // actually lands: a second account reporting different bytes for the same
  // archive is a replacement it is not allowed to make, and returning early
  // would drop the one thing worth keeping about the request. It changes no
  // answer and costs a round trip on the rare upload that has one.
  if (check.conflict) {
    await recordSourceConflict(admin, check.conflict);
  }

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
    stored = await putBlobAsset(check.path, bytes, parsed.declaration.mime);
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
  // Deleting on a failed write is free, so the store does not keep an object no
  // row will ever name. It is the object this request just made either way: on
  // a failed replacement the row still names the object it named before.
  const assetId = await writePendingAsset(
    admin,
    auth.user.id,
    parsed.declaration,
    hash,
    stored,
    image,
    check.replacing,
  );

  if (!assetId) {
    // Deleting is free, so it is always worth trying. When it fails as well the
    // object is sitting in a public store with nothing naming it, and Postgres
    // is the only place a sweep can ever find it again: `list()` is banned, so
    // an object nobody wrote down is an object nobody can reach (#113). Writing
    // the name down is the last chance to keep it findable, and it is best
    // effort too, because the upload has already failed and a second error
    // helps nobody.
    const gone = await deleteBlobAssets([stored]).then(
      () => true,
      () => false,
    );
    if (!gone) {
      await recordUnclaimedObject(admin, stored, file.size).catch(() => false);
    }
    return apiError("The asset was uploaded but its record could not be written.", 503);
  }

  // Where it came from, which is the third of the three things a report needs
  // and the only one that was not already on the row (#115). Kept while the
  // picture is pending or rejected and purged when it is approved, by a trigger
  // rather than a promise. Best effort: the row is written and the object is
  // stored, so a failure here is not worth throwing either of them away for.
  await recordUploadIp(admin, assetId, clientIp(request.headers));

  return withCors(
    NextResponse.json(buildAssetUploadBody(), {
      status: check.replacing ? 200 : 201,
      headers: { "Cache-Control": "no-store" },
    }),
  );
}

import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * Where an upload came from (issue #115).
 *
 * The issue asks for uploader identity, timestamp and source IP per asset,
 * because a report needs all three, and the first two were already on the row.
 * `20260814220300_asset_upload_ip.sql` carries the reasoning for the third,
 * including why it is a table nothing can read back and what makes it go away
 * again. This is the writing half.
 */

/**
 * The address the request came from, or null when the hub cannot tell.
 *
 * `x-real-ip` first, because the platform sets it to one address and nothing
 * else can add to it. `x-forwarded-for` is a list a client can start: a caller
 * may send one of its own and the proxy appends to it, so the leftmost entry is
 * the least trustworthy part of the header, and it is still the closest thing
 * to the client when the platform did not set the first header.
 *
 * Recording an address the sender chose would be worse than recording nothing,
 * because it looks like evidence. The fallback stays because in practice the
 * only deployment that reaches it is one behind a proxy that overwrites the
 * header, and #115 does not settle which proxies those are.
 */
export function clientIp(headers: Headers): string | null {
  const real = headers.get("x-real-ip")?.trim();
  if (real && isIpAddress(real)) return real;

  const forwarded = headers.get("x-forwarded-for")?.split(",")[0]?.trim();
  if (forwarded && isIpAddress(forwarded)) return forwarded;

  return null;
}

const IPV4 = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/;

/** Loose on purpose. Postgres `inet` is the real parser and this is here so a
 * header full of anything at all never reaches it, since a rejected insert
 * there would be discovered as a missing row much later. */
const IPV6 = /^[0-9a-f]{0,4}(:[0-9a-f]{0,4}){2,7}$/i;

export function isIpAddress(value: string): boolean {
  const four = IPV4.exec(value);
  if (four) return four.slice(1).every((part) => Number(part) <= 255);

  return IPV6.test(value);
}

/**
 * Record where one upload came from, and say nothing when there is nothing to
 * record.
 *
 * Best effort, deliberately. The object is already in the store and the row is
 * already written by the time this runs, so a failure here has two possible
 * answers: keep an upload whose provenance is one field short, or throw away a
 * stored object and an advanced operation out of 2,000 a month. The account and
 * the timestamp are on the asset row either way, and those are the identity a
 * report is actually built on.
 */
export async function recordUploadIp(
  supabase: SupabaseClient,
  assetId: string,
  ip: string | null,
): Promise<boolean> {
  if (!ip) return false;

  const { error } = await supabase.from("asset_upload_ip").insert({ asset_id: assetId, ip });

  return !error;
}

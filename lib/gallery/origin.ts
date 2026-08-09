import { headers } from "next/headers";

/**
 * The absolute origin this request arrived on. Anything coilbox is asked to fetch
 * has to be an absolute https URL, so a relative path in an import link produces
 * something that simply will not open, with no error to explain why.
 */
export async function requestOrigin(): Promise<string> {
  const incoming = await headers();
  const host = incoming.get("host") ?? "coilbox-hub.vercel.app";
  const proto = incoming.get("x-forwarded-proto") ?? "https";
  return `${proto}://${host}`;
}

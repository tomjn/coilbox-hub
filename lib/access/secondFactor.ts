import { notFound, redirect } from "next/navigation";
import { cache } from "react";
import { createClient } from "@/lib/supabase/server";
import { currentUser } from "@/lib/supabase/user";
import type { UserCapability } from "./capability";

/**
 * What `capability_access()` answers (issue #145). The database is the
 * authority, see `supabase/migrations/20261019100000_capability_second_factor.sql`.
 *
 * - `granted`: held, and this session may use it
 * - `verify`: held, but the account has a second factor and this session has
 *   not entered a code
 * - `enrol`: held, but second factors are required of everybody and the account
 *   has none
 * - `none`: not held, or nobody is signed in
 */
export type CapabilityAccess = "granted" | "verify" | "enrol" | "none";

const ANSWERS: readonly CapabilityAccess[] = ["granted", "verify", "enrol", "none"];

/** Where an account sets up a second factor. */
export const ENROL_PATH = "/account#second-factor";

/** Anything the database did not say in so many words is `none`. */
export function toCapabilityAccess(value: unknown): CapabilityAccess {
  return ANSWERS.find((answer) => answer === value) ?? "none";
}

/** The page that asks for a code, told where to go once it has one. */
export function stepUpPath(next: string): string {
  return `/account/verify?next=${encodeURIComponent(next)}`;
}

/**
 * What the visitor may do with one capability, once per request.
 *
 * For deciding what to draw and where to send somebody. It is not a gate: the
 * gate is `has_capability()` inside the database, which every policy and every
 * action already asks. A signed out visitor is not asked about, and an error is
 * `none`.
 */
export const capabilityAccess = cache(
  async (capability: UserCapability): Promise<CapabilityAccess> => {
    if (!(await currentUser())) return "none";
    const supabase = await createClient();
    const { data, error } = await supabase.rpc("capability_access", { capability });
    if (error) return "none";
    return toCapabilityAccess(data);
  },
);

/**
 * What a moderation page does once `is_moderator()` has said no.
 *
 * A moderator whose session has not entered a code is sent to enter one, and
 * one who has to enrol is sent to do that. Everybody else gets the 404 these
 * pages always gave, because whether they exist is not something a stranger
 * needs to learn. `next` is the page to come back to.
 */
export async function refuseModeration(next: string): Promise<never> {
  const access = await capabilityAccess("can_moderate");
  if (access === "verify") redirect(stepUpPath(next));
  if (access === "enrol") redirect(ENROL_PATH);
  notFound();
}

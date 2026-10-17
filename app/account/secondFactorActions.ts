"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";
import { signInNext } from "@/lib/supabase/signInNext";

/**
 * Enrolling, verifying and removing a TOTP second factor (issue #145).
 *
 * All of these use the session client, so Supabase Auth decides whether a code
 * is right and which factors belong to whom. What is checked here as well is
 * the factor id the form sent: it has to be one of this account's own, in the
 * state the action expects, before anything is asked of Supabase. The account
 * is always read from the session and never from the form.
 */

export interface EnrolState {
  error?: string;
  /** What the authenticator app needs. Only ever sent to the account it is for. */
  enrolment?: { factorId: string; qrCode: string; secret: string };
}

export interface ConfirmState {
  error?: string;
  done?: boolean;
}

export interface FactorState {
  error?: string;
}

const CODE_REFUSED =
  "That code did not match. Check the clock on the device with your authenticator and try the newest code.";
const CODE_UNREADABLE = "Type the code from your authenticator app, digits only.";
const NEEDS_CODE_FIRST =
  "Enter a code from the authenticator you already have before changing your authenticators.";

/** The digits of a code, ignoring spaces, or null for anything else. */
function readCode(raw: FormDataEntryValue | null): string | null {
  if (typeof raw !== "string") return null;
  const code = raw.replace(/\s+/g, "");
  return /^\d+$/.test(code) ? code : null;
}

/** The signed in session's id and assurance level, or a trip to sign in. */
async function session() {
  const supabase = await createClient();
  const { data } = await supabase.auth.getClaims();
  if (!data) redirect("/publish");
  return { supabase, aal: data.claims.aal };
}

/**
 * Starts adding an authenticator, and returns the QR code and secret for it.
 *
 * An account that already has one must be on an aal2 session to add another,
 * which Supabase Auth also enforces. Asking first means the message can say
 * what to do. An enrolment left unfinished is removed first, so starting again
 * does not pile up factors nobody can use.
 */
export async function startEnrolment(): Promise<EnrolState> {
  const { supabase, aal } = await session();

  const { data: factors, error: listError } = await supabase.auth.mfa.listFactors();
  if (listError || !factors) {
    return { error: "Could not read your authenticators. Try again." };
  }
  if (factors.totp.length > 0 && aal !== "aal2") return { error: NEEDS_CODE_FIRST };

  for (const factor of factors.all) {
    // An unverified factor counts for nothing at the gate, so one that will not
    // go away is untidy rather than a problem, and the enrolment goes ahead.
    if (factor.status === "unverified") await supabase.auth.mfa.unenroll({ factorId: factor.id });
  }

  const { data, error } = await supabase.auth.mfa.enroll({
    factorType: "totp",
    // Supabase wants a name unique to the account. The page shows when each
    // was added, so the name only has to differ.
    friendlyName: `Authenticator added ${new Date().toISOString()}`,
  });
  if (error || !data) {
    return { error: "Could not start setting up an authenticator. Try again." };
  }

  return {
    enrolment: { factorId: data.id, qrCode: data.totp.qr_code, secret: data.totp.secret },
  };
}

/**
 * Finishes adding an authenticator by checking a code from it.
 *
 * On success Supabase Auth raises this session to aal2 and signs out the
 * account's other sessions.
 */
export async function confirmEnrolment(
  _previous: ConfirmState,
  form: FormData,
): Promise<ConfirmState> {
  const code = readCode(form.get("code"));
  if (!code) return { error: CODE_UNREADABLE };
  const factorId = form.get("factorId");

  const { supabase } = await session();
  const { data: factors } = await supabase.auth.mfa.listFactors();
  const pending = factors?.all.find(
    (factor) => factor.id === factorId && factor.status === "unverified",
  );
  if (!pending) {
    return { error: "That setup has expired or finished already. Start again." };
  }

  const { error } = await supabase.auth.mfa.challengeAndVerify({ factorId: pending.id, code });
  if (error) return { error: CODE_REFUSED };

  revalidatePath("/account");
  return { done: true };
}

/**
 * Removes one of this account's authenticators.
 *
 * Only from an aal2 session, so a stolen first factor cannot take the second
 * one away. Supabase Auth enforces the same thing, and asking first is what
 * lets the message say so.
 */
export async function removeFactor(
  _previous: FactorState,
  form: FormData,
): Promise<FactorState> {
  const factorId = form.get("factorId");
  const { supabase, aal } = await session();
  if (aal !== "aal2") return { error: NEEDS_CODE_FIRST };

  const { data: factors } = await supabase.auth.mfa.listFactors();
  const owned = factors?.all.find((factor) => factor.id === factorId);
  if (!owned) return { error: "That authenticator is not on your account." };

  const { error } = await supabase.auth.mfa.unenroll({ factorId: owned.id });
  if (error) return { error: "Could not remove that authenticator. Try again." };

  revalidatePath("/account");
  return {};
}

/**
 * The step up: a code from an authenticator the account already has, which
 * raises this session to aal2, then back to the page that asked for it.
 *
 * `next` goes through `signInNext`, so only a path on this site is accepted.
 */
export async function verifySecondFactor(
  _previous: FactorState,
  form: FormData,
): Promise<FactorState> {
  const { supabase } = await session();

  const code = readCode(form.get("code"));
  if (!code) return { error: CODE_UNREADABLE };

  const { data: factors } = await supabase.auth.mfa.listFactors();
  const verified = factors?.totp ?? [];
  const named = form.get("factorId");
  const chosen = named
    ? verified.find((factor) => factor.id === named)
    : verified.length === 1
      ? verified[0]
      : undefined;
  if (!chosen) return { error: "Choose one of your authenticators." };

  const { error } = await supabase.auth.mfa.challengeAndVerify({ factorId: chosen.id, code });
  if (error) return { error: CODE_REFUSED };

  const next = form.get("next");
  redirect(signInNext(typeof next === "string" ? next : null, null, ""));
}

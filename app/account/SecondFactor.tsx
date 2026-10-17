"use client";

import Link from "next/link";
import { useActionState } from "react";
import { Button, buttonClass } from "@/components/Button";
import { DateTime } from "@/components/DateTime";
import { CodeField } from "./CodeField";
import {
  type ConfirmState,
  confirmEnrolment,
  type EnrolState,
  type FactorState,
  removeFactor,
  startEnrolment,
} from "./secondFactorActions";

interface Factor {
  id: string;
  createdAt: string;
}

/**
 * Adding, listing and removing authenticators (issue #145).
 *
 * `verified` is whether this session has entered a code. Without that an
 * account that already has an authenticator can neither add nor remove one,
 * which Supabase Auth enforces too, so the page offers the step up at
 * `stepUpHref` instead.
 */
export function SecondFactor({
  factors,
  verified,
  stepUpHref,
}: {
  factors: Factor[];
  verified: boolean;
  stepUpHref: string;
}) {
  const [enrol, startAction, starting] = useActionState<EnrolState, FormData>(startEnrolment, {});
  const [confirm, confirmAction, confirming] = useActionState<ConfirmState, FormData>(
    confirmEnrolment,
    {},
  );

  const mayChange = factors.length === 0 || verified;
  const showSetup = enrol.enrolment && !confirm.done;

  return (
    <div className="flex flex-col gap-4">
      {factors.length === 0 ? (
        <p className="text-sm text-neutral-400">You have no authenticator set up.</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {factors.map((factor) => (
            <li key={factor.id} className="flex items-center justify-between gap-3 text-sm">
              <span>
                Authenticator added <DateTime value={factor.createdAt} />
              </span>
              {verified ? <RemoveFactor id={factor.id} /> : null}
            </li>
          ))}
        </ul>
      )}

      {!mayChange ? (
        <p className="text-sm text-neutral-400">
          To add or remove an authenticator,{" "}
          <Link
            href={stepUpHref}
            className="text-neutral-300 underline underline-offset-4"
          >
            enter a code from one you already have
          </Link>
          .
        </p>
      ) : null}

      {confirm.done ? (
        <p role="status" className="text-sm text-green-400">
          Done. Your authenticator is set up, and any other place you were
          signed in has been signed out.
        </p>
      ) : null}

      {showSetup && enrol.enrolment ? (
        <div className="flex flex-col gap-4 rounded-md border border-neutral-800 p-4">
          <p className="text-sm text-neutral-400">
            Scan this with an authenticator app, then type the code it shows.
          </p>
          {/* eslint-disable-next-line @next/next/no-img-element -- a data URI Supabase returns, which next/image has nothing to optimise in */}
          <img
            src={enrol.enrolment.qrCode}
            alt="QR code for your authenticator app"
            className="w-48 self-start rounded bg-white p-2"
          />
          <p className="text-sm text-neutral-400">
            If you cannot scan it, type this key into the app instead:
          </p>
          <code className="select-all break-all rounded bg-neutral-900 px-2 py-1 text-sm text-neutral-100">
            {enrol.enrolment.secret}
          </code>
          <form action={confirmAction} className="flex flex-col gap-3">
            <input type="hidden" name="factorId" value={enrol.enrolment.factorId} />
            <CodeField />
            {confirm.error ? (
              <p role="alert" className="text-sm text-red-400">
                {confirm.error}
              </p>
            ) : null}
            <Button type="submit" disabled={confirming} className="self-start">
              {confirming ? "Checking..." : "Finish setting up"}
            </Button>
          </form>
        </div>
      ) : mayChange ? (
        <form action={startAction} className="flex flex-col gap-3">
          {enrol.error ? (
            <p role="alert" className="text-sm text-red-400">
              {enrol.error}
            </p>
          ) : null}
          <Button type="submit" disabled={starting} className="self-start">
            {starting
              ? "Starting..."
              : factors.length === 0
                ? "Set up an authenticator"
                : "Add a spare authenticator"}
          </Button>
        </form>
      ) : null}
    </div>
  );
}

function RemoveFactor({ id }: { id: string }) {
  const [state, action, pending] = useActionState<FactorState, FormData>(removeFactor, {});
  return (
    <form action={action} className="flex items-center gap-3">
      <input type="hidden" name="factorId" value={id} />
      {state.error ? (
        <span role="alert" className="text-red-400">
          {state.error}
        </span>
      ) : null}
      <button type="submit" disabled={pending} className={buttonClass("quiet", { size: "sm" })}>
        {pending ? "Removing..." : "Remove"}
      </button>
    </form>
  );
}

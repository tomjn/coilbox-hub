"use client";

import Link from "next/link";
import { useActionState } from "react";
import { buttonClass } from "@/components/Button";
import { LAST_METHOD_REASON, type SignInMethod } from "@/lib/account/signInMethods";
import { type UnlinkState, unlinkSignInMethod } from "./signInMethodActions";

/**
 * The ways into this account, with a remove button on each and a link button
 * for each provider not linked yet (issue #435).
 *
 * Linking is a plain form post to `app/auth/link/route.ts`, which sends the
 * browser to the provider. `mayChange` is false for an account with an
 * authenticator whose session has not entered a code, which both the route and
 * the remove action refuse, so the page offers the step up at `stepUpHref`.
 */
export function SignInMethods({
  methods,
  linkable,
  mayChange,
  stepUpHref,
}: {
  methods: SignInMethod[];
  linkable: readonly { id: string; label: string }[];
  mayChange: boolean;
  stepUpHref: string;
}) {
  const last = methods.length <= 1;

  return (
    <div className="flex flex-col gap-4">
      <ul className="flex flex-col gap-3">
        {methods.map((method) => (
          <li key={method.identityId} className="flex flex-col gap-1 text-sm">
            <div className="flex items-center justify-between gap-3">
              <span>
                {method.label}
                {method.account ? (
                  <span className="text-neutral-400"> as {method.account}</span>
                ) : null}
              </span>
              {mayChange ? (
                <UnlinkMethod identityId={method.identityId} label={method.label} last={last} />
              ) : null}
            </div>
            {mayChange && last ? (
              <p id={`last-${method.identityId}`} className="text-neutral-400">
                {LAST_METHOD_REASON}
              </p>
            ) : null}
          </li>
        ))}
      </ul>

      {!mayChange ? (
        <p className="text-sm text-neutral-400">
          To add or remove a way to sign in,{" "}
          <Link href={stepUpHref} className="text-neutral-300 underline underline-offset-4">
            enter a code from your authenticator
          </Link>
          .
        </p>
      ) : linkable.length > 0 ? (
        <div className="flex flex-wrap gap-3">
          {linkable.map((provider) => (
            <form key={provider.id} action="/auth/link" method="post">
              <input type="hidden" name="provider" value={provider.id} />
              <button type="submit" className={buttonClass("ghost", { size: "sm" })}>
                Link {provider.label}
              </button>
            </form>
          ))}
        </div>
      ) : null}
    </div>
  );
}

function UnlinkMethod({
  identityId,
  label,
  last,
}: {
  identityId: string;
  label: string;
  last: boolean;
}) {
  const [state, action, pending] = useActionState<UnlinkState, FormData>(unlinkSignInMethod, {});
  return (
    <form action={action} className="flex items-center gap-3">
      <input type="hidden" name="identityId" value={identityId} />
      {state.error ? (
        <span role="alert" className="text-red-400">
          {state.error}
        </span>
      ) : null}
      <button
        type="submit"
        disabled={last || pending}
        aria-describedby={last ? `last-${identityId}` : undefined}
        className={buttonClass("quiet", { size: "sm" })}
      >
        {pending ? "Removing..." : "Remove"}
        <span className="sr-only"> {label}</span>
      </button>
    </form>
  );
}

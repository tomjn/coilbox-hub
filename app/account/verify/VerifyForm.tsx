"use client";

import { useActionState } from "react";
import { Button } from "@/components/Button";
import { DateTime } from "@/components/DateTime";
import { type FactorState, verifySecondFactor } from "../secondFactorActions";
import { CodeField } from "../CodeField";

export function VerifyForm({
  factors,
  next,
}: {
  factors: { id: string; createdAt: string }[];
  next: string;
}) {
  const [state, action, pending] = useActionState<FactorState, FormData>(
    verifySecondFactor,
    {},
  );

  return (
    <form action={action} className="flex flex-col gap-4">
      <input type="hidden" name="next" value={next} />
      {factors.length === 1 ? (
        <input type="hidden" name="factorId" value={factors[0].id} />
      ) : (
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-2 text-sm text-neutral-400">Which authenticator?</legend>
          {factors.map((factor, index) => (
            <label key={factor.id} className="flex items-center gap-2 text-sm">
              <input
                type="radio"
                name="factorId"
                value={factor.id}
                defaultChecked={index === 0}
              />
              <span>
                Added <DateTime value={factor.createdAt} />
              </span>
            </label>
          ))}
        </fieldset>
      )}

      <CodeField />

      {state.error ? (
        <p role="alert" className="text-sm text-red-400">
          {state.error}
        </p>
      ) : null}

      <Button type="submit" disabled={pending} className="self-start">
        {pending ? "Checking..." : "Continue"}
      </Button>
    </form>
  );
}

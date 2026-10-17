const field =
  "w-full rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-400 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400";

/**
 * The box a code from an authenticator app goes in. `one-time-code` lets a
 * password manager that holds the authenticator fill it.
 */
export function CodeField() {
  return (
    <label className="flex flex-col gap-2">
      <span className="text-sm text-neutral-400">Code from your authenticator app</span>
      <input
        name="code"
        required
        inputMode="numeric"
        autoComplete="one-time-code"
        spellCheck={false}
        className={field}
      />
    </label>
  );
}

/**
 * The rules for renaming an account (issue #434).
 *
 * The bounds are the check constraint on public.profile, not a new limit:
 * length(btrim(display_name)) between 2 and 40. They are stated here so a
 * person gets a sentence before the write, and the database stays the last word.
 */
export const DISPLAY_NAME_MIN = 2;
export const DISPLAY_NAME_MAX = 40;

export type DisplayNameCheck = { name: string } | { error: string };

/**
 * Trims the name, collapses runs of whitespace to one space, and checks the
 * length. Characters are counted as Postgres counts them, so one emoji is one.
 */
export function checkDisplayName(raw: FormDataEntryValue | null | undefined): DisplayNameCheck {
  const name = typeof raw === "string" ? raw.trim().replace(/\s+/g, " ") : "";
  const length = [...name].length;
  if (length < DISPLAY_NAME_MIN) {
    return { error: `Your display name must be at least ${DISPLAY_NAME_MIN} characters.` };
  }
  if (length > DISPLAY_NAME_MAX) {
    return { error: `Your display name must be at most ${DISPLAY_NAME_MAX} characters.` };
  }
  return { name };
}

/** A sentence for a failed write, from the Postgres error code. */
export function renameErrorMessage(code: string | undefined): string {
  if (code === "23505") {
    return "That name is already in use, in any mix of capital and small letters. Choose another.";
  }
  if (code === "23514") {
    return `Your display name must be between ${DISPLAY_NAME_MIN} and ${DISPLAY_NAME_MAX} characters.`;
  }
  return "Could not save your display name. Try again.";
}

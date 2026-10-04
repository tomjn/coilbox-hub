/**
 * Whether what was typed confirms deleting the account called `expected`.
 *
 * Surrounding whitespace is ignored on both sides, since a pasted name often
 * carries some. Case is not ignored, so the visitor has to read the name rather
 * than type any word that happens to fold to it. An empty name never matches,
 * so a missing field cannot confirm anything.
 *
 * The field is read straight from form data, so it may be missing or a file.
 */
export function confirmsDeletion(
  typed: FormDataEntryValue | null | undefined,
  expected: string,
): boolean {
  if (typeof typed !== "string") return false;
  const wanted = expected.trim();
  return wanted !== "" && typed.trim() === wanted;
}

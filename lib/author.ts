/** Discord gives a display name under more than one key depending on whether the
 * account has a global name set. Publishing stores whichever it had at the time,
 * so anything that shows you your own name has to read it the same way or the
 * header and your items disagree. */
export function displayName(metadata: Record<string, unknown>): string {
  for (const key of ["full_name", "name", "preferred_username", "user_name"]) {
    const value = metadata[key];
    if (typeof value === "string" && value.trim() !== "") return value;
  }
  return "Unknown";
}

/**
 * Stands in for `@lingui/core/macro`. The hub draws English only, so a message
 * is its template with the values put in.
 */
export function t(strings: TemplateStringsArray, ...values: unknown[]): string {
  return strings.reduce(
    (out, part, i) => out + part + (i < values.length ? String(values[i]) : ""),
    "",
  );
}

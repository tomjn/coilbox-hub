/**
 * Stands in for `@lingui/core/macro` in the files vendored from coilbox, which
 * mark their labels for translation with `t`. `tsconfig.json` maps the import
 * here, so Next, `bun test` and `tsc` all resolve it without the hub installing
 * Lingui.
 *
 * The real macro only works once a compiler has rewritten it, and the hub is
 * English only, so this returns the English text with its values filled in.
 * Upstream using another macro, or `t` in another form, fails typecheck here.
 */
export function t(strings: TemplateStringsArray, ...values: unknown[]): string {
  return strings.reduce((text, part, index) => text + String(values[index - 1]) + part);
}

/**
 * The one place that knows where the durable tier is served from (issue #98).
 *
 * `public.asset.path` is tier relative and never a fully qualified URL, so the
 * host is not in the database and moving to another one is not a migration. It
 * is here, once, and every caller that needs an absolute URL goes through
 * {@link staticTierUrl} rather than writing the base into a component, a
 * template or a stored row.
 *
 * This is the durable tier rung only. The full resolution order, which is the
 * atlas, then this, then Blob, then a buildpic substitute, then a generated
 * placeholder, belongs to #108 and is not here.
 */

/**
 * Where the assets repo publishes today: https://github.com/tomjn/coilbox-assets
 * through GitHub Pages.
 *
 * A subpath rather than a domain root, so `/coilbox-assets` is part of the base
 * and joining must not drop it.
 *
 * A default rather than a required variable because this value is public, is
 * the same in every environment, and is already published in a public
 * repository. There is no deployment that wants a different one by accident,
 * only a deployment that has deliberately moved the files, and that one sets
 * the override below.
 */
export const DEFAULT_ASSET_CDN_BASE = "https://tomjn.github.io/coilbox-assets/";

/**
 * The override, read as a literal so Next.js inlines it. A `NEXT_PUBLIC_`
 * variable is baked into the browser bundle at `next build`, so repointing at
 * another host costs a redeploy. That is the right trade here: a server only
 * variable would still be frozen into any statically rendered HTML, and would
 * read as `undefined` in a client component, which fails as a silent fall back
 * to the default rather than as an error.
 *
 * Whitespace only counts as unset, the same as an empty string, so a variable
 * blanked out in a dashboard behaves like one that was never set.
 */
function configuredBase(): string | undefined {
  const value = process.env.NEXT_PUBLIC_ASSET_CDN_BASE?.trim();
  return value ? value : undefined;
}

/**
 * The base every durable tier URL is built from, always with exactly one
 * trailing slash so that joining is a concatenation and nothing has to guess.
 *
 * Never throws. A missing CDN base is not the missing Supabase config of issue
 * 54: there is a correct value to fall back to, and falling back serves the
 * right image rather than hiding a fault.
 */
export function assetCdnBase(): string {
  const base = configuredBase() ?? DEFAULT_ASSET_CDN_BASE;
  return base.replace(/\/+$/, "") + "/";
}

/**
 * The absolute URL for a tier relative `asset.path` on the durable tier.
 *
 * Joined by hand rather than with `new URL(path, base)`, which resolves the
 * path against the origin: a path that starts with a slash would come back as
 * `https://tomjn.github.io/<path>` with the `/coilbox-assets` segment eaten,
 * and a path that happens to look like a URL of its own would come back as that
 * URL. Concatenation gives a 404 in both cases, which is the failure #108 can
 * fall through.
 */
export function staticTierUrl(path: string): string {
  return assetCdnBase() + path.replace(/^\/+/, "");
}

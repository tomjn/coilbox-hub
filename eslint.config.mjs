import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  // Override default ignores of eslint-config-next.
  globalIgnores([
    "supabase/.temp/**",
    "supabase/.branches/**",
    // Agent worktrees live here, each with its own .next. The `.next/**` below
    // only matches the one at the root, so without this a lint run sweeps up
    // generated chunks from every worktree and fails on code nobody wrote.
    // CI has no worktrees and stays green, so the local run is the one lying.
    ".claude/**",
    ".vercel/**",
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
  {
    rules: {
      // Vercel Blob on Hobby gets 2,000 advanced operations a month, and
      // `list()`, `head()` and `copy()` all spend them for answers a
      // `public.asset` row already has. Going over removes Blob access for 30
      // days and cannot be paid through, so the ban is a lint error rather
      // than a note somebody has to have read. `lib/assets/blob.ts` exports
      // the two calls that are worth making and explains the rest.
      //
      // `@vercel/blob/client` is deliberately not restricted: it exports the
      // client direct upload path (#104) and none of the metered lookups.
      "no-restricted-imports": [
        "error",
        {
          paths: [
            {
              name: "@vercel/blob",
              message:
                "Import from @/lib/assets/blob instead. It is the only place this package is allowed, and it deliberately does not expose list(), head() or copy().",
            },
          ],
        },
      ],
    },
  },
  {
    files: ["lib/assets/blob.ts"],
    rules: { "no-restricted-imports": "off" },
  },
]);

export default eslintConfig;

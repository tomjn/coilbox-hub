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
      // `supabase.storage` is a property access rather than an import, so it
      // needs `no-restricted-syntax` rather than `no-restricted-imports`. The
      // staging bucket is private and content addressed, and
      // `lib/assets/staging.ts` is the only place that is allowed to know
      // that, or to reach for a listing call nothing here should ever make.
      "no-restricted-syntax": [
        "error",
        {
          selector: "MemberExpression[property.name='storage']",
          message:
            "Use @/lib/assets/staging instead of supabase.storage directly. It is the only place the staging bucket's Storage client is allowed.",
        },
      ],
    },
  },
  {
    files: ["lib/assets/staging.ts"],
    rules: { "no-restricted-syntax": "off" },
  },
]);

export default eslintConfig;

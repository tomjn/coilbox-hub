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
]);

export default eslintConfig;

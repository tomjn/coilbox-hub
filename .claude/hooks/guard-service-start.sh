#!/usr/bin/env bash
# PreToolUse hook for Bash. Blocks starting Supabase or the dev server directly,
# because scripts/local-services.sh can only stop what it started.
set -uo pipefail

command="$(jq -r '.tool_input.command // ""')"

prefix='(^|[;&|(])[[:space:]]*((nohup|timeout[[:space:]]+[0-9]+|[A-Za-z_]+=[^[:space:]]+)[[:space:]]+)*'
start='(supabase[[:space:]]+(db[[:space:]]+)?start|(bun|npm|pnpm|yarn)[[:space:]]+(run[[:space:]]+)?dev|((bunx|npx)[[:space:]]+)?next[[:space:]]+dev)'
end='([[:space:];&|)>]|$)'

if [[ "$command" =~ $prefix$start$end ]]; then
  jq -n '{
    hookSpecificOutput: {
      hookEventName: "PreToolUse",
      permissionDecision: "deny",
      permissionDecisionReason: "Start local services with scripts/local-services.sh up [supabase|dev], and stop them with scripts/local-services.sh down before the session ends. A service started directly is not recorded, so nothing stops it."
    }
  }'
fi
exit 0

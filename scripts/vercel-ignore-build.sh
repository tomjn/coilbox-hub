#!/usr/bin/env bash
# Vercel's Ignored Build Step, set as `ignoreCommand` in vercel.json. Exit 0
# skips the deploy, and any other exit builds.
#
# Skips a deploy when nothing the site runs has changed since the last one.
# Every deploy starts the page and data caches empty, because their keys carry
# the build id, so a deploy that changes nothing still costs a function run on
# the first visit to every page.
#
# With no previous deploy to compare against, or a commit git cannot find, it
# builds.
set -u

prev="${VERCEL_GIT_PREVIOUS_SHA:-}"
[ -n "$prev" ] || exit 1

git diff --quiet "$prev" "${VERCEL_GIT_COMMIT_SHA:-HEAD}" -- . \
  ':(exclude)docs' \
  ':(exclude)scripts' \
  ':(exclude)supabase' \
  ':(exclude).github' \
  ':(glob,exclude)**/*.md' \
  ':(glob,exclude)**/*.test.ts' \
  ':(glob,exclude)**/*.test.tsx'

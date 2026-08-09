# Coilbox Hub

The public gallery for content made in [Coilbox](https://github.com/tomjn/coilbox): battle presets, warpath and conquest challenges, setup packs and scenarios.

Right now this is a placeholder. The design it is being built to is `docs/superpowers/specs/2026-08-09-community-gallery-design.md` in the coilbox repo.

## How it will work

Coilbox already has a versioned share format, and shipped builds already import from an https URL through `coilbox://import?url=`. So publishing is pasting a share code or uploading an exported file here, and importing is a link that opens in the app. Neither needs a Coilbox release.

Data and auth live in Supabase. Sign in is via Discord, and it is only needed to publish, update or withdraw something. Browsing and importing need no account.

## Running it

```
bun install
bun dev
```

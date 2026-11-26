# Sending a replay to the hub

This is how Coilbox sends a match replay to the hub (#662). The hub keeps one copy of each match, keyed by the 16 byte game id in the replay's header. Several players can send the same match. Only the first uploads the file. The others are recorded as having sent it.

Nothing is shown on the hub until the check in #663 has compared the summary with the file.

## Starting an upload

`POST /api/v1/replays/uploads` with the signed in user's bearer token and a JSON body:

```json
{
  "game_id": "9f2c4b1ad07e5386c1f4a92b6e8d0375",
  "sha256": "77ab12c4d0e9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b4c3d2e1f0a9b8c7d6e5f4a3",
  "size": 684112,
  "name": "Alice",
  "summary": { "v": 1, "...": "see below" }
}
```

| Field | What it is |
|---|---|
| `game_id` | The game id from the replay's header, as 32 lower case hex characters. |
| `sha256` | The SHA-256 of the `.sdfz` file, as 64 lower case hex characters. |
| `size` | The file's size in bytes. The mirror refuses a file of any other size. |
| `name` | The name the sender was in the battle under. It must be a player's or a spectator's `name` in the summary, with the same case. |
| `summary` | What Coilbox worked out from the file, below. |

The hub has no limit of its own on the body. The web server refuses bodies over 5 MB (`deploy/nginx.conf`).

### Answers

| Status | Body | What Coilbox does |
|---|---|---|
| `201` | `upload` | Upload the file to the mirror with the token, as for archives. |
| `200` | `upload` | The same sender asked again while their upload is reserved. Use the new token. For the same `sha256` and `size` the upload `id` is unchanged, so the mirror resumes what it holds. |
| `200` | `replay` | The hub already holds this match. Upload nothing. The sender is recorded. |
| `409` | `reason: "uploading"` | Someone else is uploading this match. Ask again after `reserved_until`, in Unix seconds, or sooner. The sender is not recorded. |
| `422` | `reason: "not_in_replay"` | `name` is not a player or spectator in the summary. |
| `400` | `reason: "bad_request"` | `error` says what is wrong. |
| `401` | | Not signed in. |
| `503` | `reason: "no_replay_mirror"` or `"not_configured"` | No mirror takes replays yet. |

`upload` is:

```json
{
  "id": "0b6f6c2e-6d0a-4c43-9d55-2f9a7c1e8b10",
  "game_id": "9f2c4b1ad07e5386c1f4a92b6e8d0375",
  "state": "uploading",
  "mirror": "jj-darkstars",
  "filename": "9f2c4b1ad07e5386c1f4a92b6e8d0375.sdfz",
  "upload_url": "https://mirror.example/upload.php",
  "limits_url": "https://mirror.example/limits.php",
  "token": "...",
  "start_by": 1794787560,
  "reserved_until": 1794873660
}
```

The token's kind is `replay`. The upload must start by `start_by`. If it has not finished by `reserved_until`, the reservation lapses and another sender can take the match.

`replay` is:

```json
{ "game_id": "9f2c4b1ad07e5386c1f4a92b6e8d0375", "state": "checking", "url": null }
```

`state` is `checking` while #663 checks the file, and `listed` once it passes. `url` is the replay's hub page once it is listed, and `null` until then.

## When the upload has finished

Coilbox does nothing more. The mirror reports the file's hash to the hub, as it does for archives. The hub then queues the check in #663.

## The check

The hub fetches the file from the mirror it went to and reads it. It refuses the replay when the file is not the one reported, cannot be read, decompresses to more than 64 MB, has another game id, has no game over or has no end of game block. It then compares the summary with the file, and any difference refuses it:

- `engine`, `game`, `map`, `started_at` and `duration` must be the file's exactly.
- `players` and `spectators` must be the start script's, in any order.
- Each player's `faction` is the faction they played, as [Factions](#factions) says.
- `winners` must be the end of game block's, in any order.
- `totals` must have one entry for each team the end of game block has a sample for, with that team's last sample. Fractions are compared as the 32 bit floats the engine writes.
- `start_positions` must have one entry for each team with a start position before the first frame, as 32 bit floats.
- `build_orders` must hold each team's orders as Coilbox's `build_orders.rs` reads them, in order. A team with no orders may be left out.
- For Beyond All Reason, each unit's `name` must be the one the replay's unit list gives its `unit_def_id`. For another game, when the hub's catalogue has the exact release in full, each `name` must be one of its units. Costs, `display_name`, `build_time` and `category` are not checked.
- The first sender's `name` must be a player or spectator in the file. A later sender whose name is not is dropped from the replay's senders.

A refused replay is removed from the mirrors and its game id can be sent again. Moderators find the reason in `replay_refusal`.

## Factions

A player played the faction of their last pick that counts. A player with no such pick played their team's `side` in the start script. Which picks count depends on the game, as the game itself accepts them:

| The game type starts with | A pick counts when it is sent |
|---|---|
| `SplinterFaction ` or `Total Annihilation: Recoil ` | While the faction phase is open: before frame 900, and before frame `n + 91` once every team with a player has picked, where `n` is the frame of the pick by the last team to make its first |
| Anything else | Before the first frame |

A pick's frame is the number of the last new frame or key frame packet before it, or `-1` before the first.

| The last pick | `faction` the check expects | What the hub stores |
|---|---|---|
| None | The start script's `side`, or `null` when it has none | The same |
| A faction name (`PICKFACTION\|`) | That name, exactly as sent | The same |
| A start unit the hub's catalogue has a faction for | The faction's name in the catalogue, which is the game's own name for the side, such as `Legion` | The same |
| A start unit the catalogue has no faction for | Not checked | The unit's name, such as `legcom` |
| A start unit nothing names | Not checked | `null` |

The catalogue has a faction for a start unit when a game with the replay's game name lists the unit in its start units and puts it in one of its factions. A start unit's name comes from the replay's unit list, which Beyond All Reason's carries. For a replay with no unit list it comes from the summary's `units`, so list each start unit a counted pick names there, even though no build order has it. The hub checks that name as it does the other [unit names](#the-check).

## The summary

All keys are required. Numbers may be whole or have a fraction unless the table says whole. Entries in `build_orders[].orders` may carry more keys than these, and the hub keeps them.

| Key | What it is |
|---|---|
| `v` | `1`. |
| `engine` | The engine version, as in the header. |
| `game` | The game name and version, as in the start script's `gametype`. |
| `map` | The map name, as in the start script's `mapname`. |
| `started_at` | When the match started, in whole Unix seconds, from the header. |
| `duration` | The match length in whole game seconds, from the header. `0` when there was no game over. |
| `players` | One `{name, team, ally_team, faction}` for each player. `team` and `ally_team` are whole numbers from the start script. `faction` is the faction the player played (see [Factions](#factions)), as text, or `null` when the replay does not say. |
| `spectators` | The spectators' names. |
| `winners` | The winning ally team numbers, from the end of game block. |
| `totals` | One entry for each team: `team`, then the team's last statistics sample, as `metal_used`, `energy_used`, `metal_produced`, `energy_produced`, `metal_excess`, `energy_excess`, `metal_received`, `energy_received`, `metal_sent`, `energy_sent`, `damage_dealt`, `damage_received`, `units_produced`, `units_died`, `units_received`, `units_sent`, `units_captured`, `units_out_captured` and `units_killed`. |
| `start_positions` | One `{team, x, z}` for each start position chosen. |
| `build_orders` | One `{team, orders}` for each team. `orders` is a list of `{frame, unit_def_id}`, in the order given. Both are whole numbers. |
| `units` | One `{unit_def_id, name, display_name, metal, energy, build_time, category}` for each unit the match used. `name` is the unit's internal name. `display_name`, `build_time` and `category` may be `null`. Every `unit_def_id` in `build_orders` must be listed here, once. |

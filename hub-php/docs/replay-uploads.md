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
| `410` | `reason: "removed"` | A moderator took this match down (#671), or took down the same file under another game id. Upload nothing, and do not send it again. The sender is not recorded. |
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

Coilbox sends nothing more. The mirror reports the file's hash to the hub, as it does for archives. The hub then queues the check in #663. To learn how it went, [ask for the replay's status](#asking-what-became-of-a-replay).

## Asking what became of a replay

`GET /api/v1/replays/{game_id}/uploads/status` with the signed in user's bearer token (#694). It changes nothing: it records no sender, and neither reserves the match nor renews a reservation. Use it to wait for the check, not another `POST`.

| Status | Body | When |
|---|---|---|
| `200` | `replay` | You sent this match, or it is listed. |
| `401` | | Not signed in. |
| `404` | `reason: "not_sent"` | The match is not listed and the hub has no record that you sent it. This includes an upload of yours that lapsed and that another sender then took over. |

`replay` always has these keys:

```json
{
  "replay": {
    "game_id": "9f2c4b1ad07e5386c1f4a92b6e8d0375",
    "state": "refused",
    "url": null,
    "reserved_until": null,
    "refusal": {
      "reason": "summary_mismatch",
      "message": "What was sent about the match does not agree with the replay file.",
      "refused_at": 1794787900
    }
  }
}
```

| `state` | What it means | What Coilbox does |
|---|---|---|
| `uploading` | The match is reserved for your upload until `reserved_until`, and the mirror has not reported the file. | Finish the upload, or keep asking. |
| `lapsed` | The reservation ran out at `reserved_until` and the mirror has not reported the file. If the report still arrives before anyone else takes the match, the state becomes `checking`. | Tell the player the upload did not finish. Sending it again starts a new reservation. |
| `checking` | The hub holds the file and is checking it. | Ask again. |
| `listed` | The replay is on the hub at `url`. | Link it. |
| `refused` | The check refused the replay, as `refusal` says. The game id can be sent again. | Show `refusal.message`. |
| `removed` | A moderator took the replay down (#671). Only its senders see this. To anyone else the match is `not_sent`. | Tell the player it was removed, and do not send it again. |

`url` is `null` unless `state` is `listed`. `reserved_until`, in Unix seconds, is `null` unless `state` is `uploading` or `lapsed`. `refusal` is `null` unless `state` is `refused`.

While the hub holds the match for you, you get its current state. Otherwise your latest refusal for this game id comes before somebody else's listed copy.

`refusal.message` is a sentence a player can read. `refusal.reason` is one of these codes, and more may be added:

| `reason` | `message` |
|---|---|
| `wrong_file` | The file that reached the hub was not the one Coilbox described. You can send it again. |
| `unreadable` | The hub could not read the replay file. It may be damaged or too large. |
| `wrong_game_id` | The replay file belongs to a different match. |
| `remix` | The file sent was a remix, a copy of a replay changed to run on another game. The hub only takes the original replay. |
| `no_game_over` | The replay stops before the match ended. The hub only takes finished matches. |
| `no_end_of_game` | The replay has no end of match results. The hub only takes finished matches. |
| `summary_mismatch` | What was sent about the match does not agree with the replay file. |
| `sender_not_in_replay` | The name this match was sent under did not play or watch it. |
| `check_failed` | The hub could not finish checking the replay. You can send it again. |

A replay refused before this route existed answers `refused` only to the player who uploaded it.

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

The hub refuses a remix, a copy of a replay that Coilbox has pointed at another game (#700). A file is a remix when the `[coilbox]` section of its start script's `[game]` has `remix=1`. A remix keeps the original match's game id and the hub holds one replay for each match, so it would take the original's place. This is a backstop, since Coilbox never sends a remix. The start request cannot tell one apart, so the check refuses it after the upload, and the original can then be sent.

The unit list and the end of game awards are Lua messages in the replay too. The hub reads them only from a game whose type starts with `Beyond All Reason `, and only when sent to LuaRules (script `100`), as Coilbox does. Any other replay has no unit list and no awards.

A refused replay is removed from the mirrors and its game id can be sent again. Moderators find the reason in `replay_refusal`, and everyone who sent it can [ask why](#asking-what-became-of-a-replay).

Once a replay is listed, anybody signed in can send an analysis run for it, as [replay-analyses.md](replay-analyses.md) says. Once an analysis has passed, anybody signed in can send the replay's [track](#tracks).

## Factions

A player played the faction of their last pick that counts. A player with no such pick played their team's `side` in the start script, except in Splinter Faction and TA Recoil with the faction choice on.

A pick is a Lua message in the replay, sent to LuaRules (script `100`). The hub reads one format for each of these games, the same as Coilbox does, and no pick in any other game:

| The game type starts with | A pick is |
|---|---|
| `Beyond All Reason ` | `changeStartUnit` and the start unit's def id in digits |
| `SplinterFaction `, `Total Annihilation: Recoil ` or `Total Atomic Power ` | The byte `0x8A` and the start unit's def id in digits |
| `Metal Factions ` | `PICKFACTION\|` and the faction's name |

Which picks count depends on the game, as the game itself accepts them:

| The game type starts with | A pick counts when it is sent |
|---|---|
| `SplinterFaction ` or `Total Annihilation: Recoil ` | While the faction phase is open: before frame 900, and before frame `n + 91` once every team with a player has picked, where `n` is the frame of the pick by the last team to make its first |
| Anything else | Before the first frame |

A pick's frame is the number of the last new frame or key frame packet before it, or `-1` before the first.

Splinter Faction and TA Recoil give each team one faction. In these two games a player's last pick is the last pick that counts from any player on their team, spectators left out. The faction choice is on unless the start script's mod options turn it off:

| The game type starts with | The faction choice is off when |
|---|---|
| `SplinterFaction ` | `skipfactionchooser` is `1` or `true` |
| `Total Annihilation: Recoil ` | `factionchoice` is `0` or `false`, or `skippregame` is a number other than `0` |

With the faction choice off, no pick counts and every player played their team's `side`. With it on, the game gives a team with no pick a random faction when the phase closes. The file does not say which, so the hub does not know that player's faction.

| The last pick | `faction` the check expects | What the hub stores |
|---|---|---|
| None, in Splinter Faction or TA Recoil with the faction choice on | Not checked | `null` |
| None, in any other match | The start script's `side`, or `null` when it has none | The same |
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

## Tracks

A track is Coilbox's recording of where everything was during a replay, as `docs/reference/replay-track-format.md` in tomjn/coilbox sets out (#665). The hub keeps one track for each replay and track format version, the first that passes its check. It takes format version 1 only.

### Starting a track upload

`POST /api/v1/replays/{game_id}/tracks/uploads` with the signed in user's bearer token and a JSON body:

```json
{ "format_version": 1, "sha256": "c019be55a4d0e9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b4c3d2e1f0a9b8c7d6e5f4", "size": 233823 }
```

| Field | What it is |
|---|---|
| `format_version` | The format version in the track's header. |
| `sha256` | The SHA-256 of the `.cbtrack` file, as 64 lower case hex characters. |
| `size` | The file's size in bytes. The mirror refuses a file of any other size. |

Any signed in user can send a track. They need not have been in the match.

| Status | Body | What Coilbox does |
|---|---|---|
| `201` | `upload` | Upload the file to the mirror with the token, as for archives. |
| `200` | `upload` | The same sender asked again while their upload is reserved. Use the new token. For the same `sha256` and `size` the upload `id` is unchanged, so the mirror resumes what it holds. |
| `200` | `track` | The hub already has a track for this replay. Upload nothing. |
| `409` | `reason: "uploading"` | Someone else is uploading this track. Ask again after `reserved_until`, in Unix seconds, or sooner. |
| `409` | `reason: "no_analysis"` | No analysis of this replay has passed yet. Send one first, and ask again once its check has finished. |
| `404` | `reason: "not_listed"` | The hub has no listed replay with this game id. |
| `422` | `reason: "unknown_format"` | The hub does not take this format version. |
| `400` | `reason: "bad_request"` | `error` says what is wrong. |
| `401` | | Not signed in. |
| `503` | `reason: "no_track_mirror"` or `"not_configured"` | No mirror takes tracks yet. |

`upload` has the same keys as a replay's, and `format_version`. Its `filename` is the game id followed by `.cbtrack`, and the token's kind is `track`. A reservation lapses at `reserved_until` as a replay's does.

`track` is:

```json
{
  "game_id": "9f2c4b1ad07e5386c1f4a92b6e8d0375",
  "format_version": 1,
  "state": "listed",
  "recorder_version": 5,
  "size": 233823,
  "sha256": "c019be55...",
  "url": "https://mirror.example/tracks/9f2c4b1ad07e5386c1f4a92b6e8d0375.cbtrack"
}
```

`state` is `checking` or `listed`. `recorder_version` is the one in the track's header, and is `null` while `checking`. `url` is where to download the file. It is `null` while `checking`, and while the mirror the track was uploaded to is switched off or unhealthy.

### Getting a replay's track

`GET /api/v1/replays/{game_id}/tracks` needs no sign in. It answers `200` with `{"tracks": [track, ...]}`, one entry for each listed format version, newest first, and an empty list when there is none. It answers `404` with `reason: "not_listed"` for a game id with no listed replay.

### The track check

When the mirror reports the file's hash, the hub queues the check. It fetches the file and reads its header and info chunk. It refuses the track for these reasons:

| Reason | When |
|---|---|
| `wrong_file` | The file is not the one reported. |
| `unreadable` | The file is not a track, or its header or info chunk cannot be read. |
| `unknown_format` | The header's format version is not 1. |
| `wrong_game_id` | The header's game id is not the replay's. |
| `length_mismatch` | The track is not at 30 frames a second, or its frames from first to last do not come to the replay's `duration` in whole seconds. One frame more is allowed, for the block the recorder writes at game over. |
| `team_mismatch` | A team in the replay's start script is missing, is on another ally team, or has another leader name. Or the track has any other team, apart from one with no leader numbered after the last, which is the engine's Gaia team. |
| `check_failed` | The check did not finish in three tries. |

A refused track is removed from the mirrors, and another can be sent. Moderators find the reason in `replay_track_refusal`. Removing a replay removes its tracks.

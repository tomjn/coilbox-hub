# Sending a replay analysis to the hub

This is how Coilbox sends the result of an analysis run to the hub (#664). An analysis run plays a replay back in the engine under Coilbox's replay logger. The hub cannot run one, so players send theirs.

Send one only for a run Coilbox found reproduced the match, with no disagreements. The hub checks it against the replay with the same rules and refuses one that differs.

## Sending one

`POST /api/v1/replays/{game_id}/analyses` with the signed in user's bearer token and a JSON body:

```json
{
  "logger_version": 7,
  "events": "{\"kind\":\"header\",\"format\":1,...}\n{\"kind\":\"game_start\",\"frame\":0}\n..."
}
```

| Field | What it is |
|---|---|
| `game_id`, in the path | The game id of a replay the hub lists, as 32 lower case hex characters. |
| `logger_version` | Coilbox's `LOGGER_VERSION` for the logger that made the run, in `demo/analysis/log.rs`. A whole number, at least 1. The log does not hold it. |
| `events` | The run's `events.jsonl` as the logger wrote it, as one string. Send only the logger's lines. Coilbox's own first line in its stored copy, `"kind":"analysis"`, is not part of it. |

The final totals are not sent apart from the log. They are the log's `game_over` line.

Anybody signed in can send an analysis for any listed replay, not only the person who sent the replay. The hub has no limit of its own on the body. The web server refuses bodies over 5 MB (`deploy/nginx.conf`). The logs Coilbox has measured were 3 KB to 181 KB.

### Answers

| Status | Body | What it means |
|---|---|---|
| `202` | `analysis` | The hub will check it. |
| `200` | `analysis` | The same person sent this log for this logger version before. The answer is that first one, in whatever state its check left it. Nothing is checked again. |
| `404` | `reason: "not_listed"` | The hub lists no replay with this game id. Send the replay first. |
| `400` | `reason: "bad_request"` | `error` says what is wrong. |
| `401` | | Not signed in. |

`analysis` is:

```json
{ "id": 12, "game_id": "9cf5d76a4468b019403831ac99faf0f7", "logger_version": 7, "state": "checking", "reason": null }
```

## The check

A queued job checks the analysis. It refuses one when:

| `reason` | What differs |
|---|---|
| `not_listed` | The replay stopped being listed before the check. |
| `unreadable` | A line is not a JSON object with a `kind`. |
| `no_header` | There is no `header` line. |
| `unknown_format` | The header's `format` is not 1. |
| `wrong_map` | The header's `map` is not the replay's map. |
| `no_game_over` | There is no `game_over` line. |
| `outcome_mismatch` | The winners, in any order, the game seconds (the game over frame divided by 30, rounded down), or the number of teams differ from the replay's. |
| `no_totals` | The replay has no final totals for a team. |
| `total_mismatch` | A team's `samples` or any of its 19 totals differ from the replay's end of game block. Fractions are compared as 32 bit floats. |
| `count_mismatch` | A team's lines differ from its unit counts. There must be one `unit_created` line per unit produced and one `unit_destroyed` line per unit died, leaving out the last death line of each unit listed in `game_over.dying`. There must also be one `unit_given` line per unit received or sent, and one with `captured` true per unit captured or captured from the team. A gift counts for its `team` and its `from`. |
| `check_failed` | The check did not finish after three tries. |

These are the rules Coilbox applies in `demo/analysis/divergence.rs`, except the count of desync warnings, which is in the engine's output and not the log.

Positions, kills and damage cannot be checked this way. A log that was edited but kept the totals right would pass.

## What the hub keeps

A replay keeps one analysis: the first that passed from the newest logger version sent. Each one sent ends in one of these states:

| `state` | What happened |
|---|---|
| `kept` | It passed and is the replay's analysis. |
| `agreed` | It passed for the logger version the replay keeps, after another. |
| `older` | It passed, but the replay keeps an analysis from a newer logger version, now or since. |
| `refused` | It failed. `reason` says why. |

The agreement count is the number of different people whose analysis passed for the kept logger version, the kept one's sender included. The same person sending twice counts once, because the count is there to show how many separate runs matched. A newer logger version that passes replaces the kept analysis and starts its own count.

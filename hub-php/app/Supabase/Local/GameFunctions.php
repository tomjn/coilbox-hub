<?php

namespace App\Supabase\Local;

use App\Domain\Points\CommunityPoints;
use App\Supabase\Client;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The game catalogue's Postgres functions, done in PHP on the local tables:
 * `submit_game_facts` (20261021120000_game_base_game.sql),
 * `offer_game_download_sources` and `decide_game_download_offer`
 * (20260918180000_game_download_offers.sql) and `game_sample_units`
 * (20260918130000_game_sample_units.sql). Each keeps its grants, its locking
 * and its answers.
 */
final class GameFunctions
{
    /** Function => roles granted execute. */
    private const GRANTS = [
        'submit_game_facts' => ['service_role'],
        'offer_game_download_sources' => ['service_role'],
        'decide_game_download_offer' => ['authenticated'],
        'game_sample_units' => ['anon', 'authenticated', 'service_role'],
    ];

    public static function handles(string $function): bool
    {
        return isset(self::GRANTS[$function]);
    }

    public static function writes(string $function): bool
    {
        return $function !== 'game_sample_units';
    }

    /** @param array<string, mixed> $args */
    public static function call(Client $client, string $function, array $args): mixed
    {
        $caller = new Caller($client);
        if (! in_array($caller->role(), self::GRANTS[$function], true)) {
            throw new LocalException('permission denied for function '.$function, 401, '42501');
        }
        // Arguments as Postgres receives them: JSON, objects kept as objects.
        $args = json_decode(json_encode($args, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        return match ($function) {
            'submit_game_facts' => self::submitGameFacts($caller, $args->p_submission ?? null, $args->p_submitted_by ?? null),
            'offer_game_download_sources' => self::offerGameDownloadSources($args->p_shortname ?? null, $args->p_sources ?? null, $args->p_offered_by ?? null),
            'decide_game_download_offer' => self::decideGameDownloadOffer($caller, (int) ($args->p_offer_id ?? 0), (bool) ($args->p_accept ?? false)),
            'game_sample_units' => self::gameSampleUnits($caller, (array) ($args->p_shortnames ?? []), $args->p_count ?? null),
        };
    }

    /**
     * One game's facts, decided and written in one transaction. See the SQL
     * function for why each rule is what it is. Answers one entry per faction
     * then one per unit, in the order sent.
     *
     * @return list<array{kind: string, name: ?string, outcome: ?string, said: ?string}>
     */
    private static function submitGameFacts(Caller $caller, mixed $submission, ?string $submittedBy): array
    {
        $submission = $submission instanceof stdClass ? $submission : new stdClass;

        return DB::transaction(function () use ($caller, $submission, $submittedBy) {
            $now = Values::now();
            $shortname = self::text($submission, 'shortname');
            $game = $shortname === null ? null : DB::table('game')->where('shortname', $shortname)->lockForUpdate()->first();
            $isNew = $game === null;
            if ($game === null) {
                // The first report of a shortname creates the game.
                $startUnits = is_array($submission->start_units ?? null) ? self::texts($submission->start_units) : null;
                $row = Writes::complete($caller, 'game', [
                    'shortname' => $shortname, 'display_name' => null, 'description' => null, 'links' => [],
                    'start_units' => $startUnits, 'submitted_by' => $submittedBy,
                ], $now);
                Writes::insert($caller, 'game', $row);
                $game = DB::table('game')->where('id', $row['id'])->lockForUpdate()->first();
            }
            $gameId = (string) $game->id;
            $release = self::text($submission, 'release');
            $complete = self::boolean(self::text($submission, 'complete'));

            // Name, description and links fill only gaps. The base game and the
            // start units replace what is held when they are sent.
            $held = (array) $game;
            $links = is_array($submission->links ?? null) ? $submission->links : null;
            $values = [
                'display_name' => $held['display_name'] ?? self::text($submission, 'display_name'),
                'description' => $held['description'] ?? self::text($submission, 'description'),
            ];
            if (json_decode((string) $held['links'], true) === [] && $links !== null && $links !== []) {
                $values['links'] = $links;
            }
            $baseGame = Tables::btrim((string) self::text($submission, 'base_game'));
            if ($baseGame !== '') {
                $values['base_game'] = $baseGame;
            }
            if (is_array($submission->start_units ?? null)) {
                $values['start_units'] = self::texts($submission->start_units);
            }
            Writes::update($caller, 'game', [$held], $values, $now);

            // The release. last_seen_at moves on every report.
            $version = DB::table('game_version')->where('game_id', $gameId)->where('version', $release)->lockForUpdate()->first();
            $isNew = $isNew || $version === null;
            if ($version === null) {
                Writes::insert($caller, 'game_version', Writes::complete($caller, 'game_version', ['game_id' => $gameId, 'version' => $release], $now));
            } else {
                DB::table('game_version')->where('id', $version->id)->update(['last_seen_at' => $now]);
            }

            $results = [];
            if (is_array($submission->factions ?? null)) {
                $results = self::factions($gameId, $submission->factions);
            }
            [$units, $refused] = self::units($gameId, $submission->units ?? null, $release, $complete, $now);
            array_push($results, ...$units);

            if ($complete) {
                // Retired rather than deleted, because an old replay still names it.
                $named = [];
                foreach (is_array($submission->units ?? null) ? $submission->units : [] as $entry) {
                    $name = self::text($entry->unit ?? null, 'name');
                    if ($name !== null) {
                        $named[] = $name;
                    }
                }
                $retire = DB::table('game_unit')->where('game_id', $gameId)->whereNull('removed_at');
                if ($named !== []) {
                    $retire->whereNotIn('unit_name', $named);
                }
                $retire->update(['removed_at' => $now, 'updated_at' => $now]);
                if (! $refused) {
                    DB::table('game_version')->where('game_id', $gameId)->where('version', $release)->update(['reported_in_full' => true]);
                }
            }

            // Factions are always accepted, so only units can show new facts.
            $reported = array_filter($units, fn (array $unit) => in_array($unit['outcome'], ['accepted', 'recorded'], true));
            if ($isNew || $reported !== []) {
                CommunityPoints::award($submittedBy, CommunityPoints::GAME_FACTS, $gameId.':'.$release);
            }

            return $results;
        });
    }

    /**
     * The sides, brought in line with the submission: a side it names is
     * updated in place, a side it leaves out is deleted, and each is answered
     * by name.
     *
     * @param  list<mixed>  $factions
     * @return list<array<string, mixed>>
     */
    private static function factions(string $gameId, array $factions): array
    {
        $keys = [];
        foreach ($factions as $entry) {
            $key = self::text($entry, 'key');
            if ($key !== null) {
                $keys[] = $key;
            }
        }
        $gone = DB::table('game_faction')->where('game_id', $gameId);
        if ($keys !== []) {
            $gone->whereNotIn('key', $keys);
        }
        $gone->delete();

        $held = DB::table('game_faction')->where('game_id', $gameId)->pluck('id', 'key')->all();
        $seen = [];
        $results = [];
        foreach ($factions as $entry) {
            $key = self::text($entry, 'key');
            $name = self::text($entry, 'name');
            $outcome = 'accepted';
            $said = null;
            try {
                $row = ['game_id' => $gameId, 'key' => $key, 'name' => $name];
                if (($violation = Tables::violation('game_faction', $row)) !== null) {
                    throw $violation;
                }
                if (in_array($key, $seen, true)) {
                    // Named twice in one submission: the second is refused, as
                    // the plain insert on the identity index refuses it.
                    throw new LocalException('duplicate key value violates unique constraint "game_faction_identity_idx"', 409, '23505');
                }
                if (isset($held[$key])) {
                    DB::table('game_faction')->where('id', $held[$key])->update(['name' => $name]);
                } else {
                    $held[$key] = DB::table('game_faction')->insertGetId($row);
                }
                $seen[] = $key;
            } catch (LocalException $e) {
                $outcome = 'refused';
                $said = $e->getMessage();
            }
            $results[] = ['kind' => 'faction', 'name' => $key, 'outcome' => $outcome, 'said' => $said];
        }

        return $results;
    }

    /**
     * Each unit and its revision for this release. A unit the tables refuse
     * costs itself and not the units around it.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private static function units(string $gameId, mixed $entries, ?string $release, bool $complete, string $now): array
    {
        if ($entries === null) {
            return [[], false];
        }
        if (! is_array($entries)) {
            throw new LocalException('cannot extract elements from an object', 400, '22023');
        }
        // The game row is locked, so these are the rows to decide against.
        $held = [];
        foreach (DB::table('game_unit')->where('game_id', $gameId)->get(['id', 'unit_name', 'facts_digest']) as $unit) {
            $held[$unit->unit_name] = $unit;
        }
        $revisions = [];
        if ($release !== null) {
            foreach (DB::table('game_unit_revision')->join('game_unit', 'game_unit.id', '=', 'game_unit_revision.unit_id')
                ->where('game_unit.game_id', $gameId)->where('game_unit_revision.version', $release)
                ->pluck('game_unit_revision.unit_id') as $unitId) {
                $revisions[(int) $unitId] = true;
            }
        }

        $results = [];
        $refused = false;
        foreach ($entries as $entry) {
            $unit = $entry->unit ?? null;
            $name = self::text($unit, 'name');
            $outcome = null;
            $said = null;
            try {
                $digest = self::text($entry, 'facts_digest');
                $buildOptions = self::texts(self::property($unit, 'build_options'));
                $morphTargets = self::has($unit, 'morph_targets') ? $unit->morph_targets : [];
                $stats = self::has($unit, 'stats') && $unit->stats !== null ? $unit->stats : new stdClass;
                $facts = [
                    'full_name' => self::text($unit, 'full_name'),
                    'faction_key' => self::text($unit, 'faction_key'),
                    'build_options' => $buildOptions,
                    'morph_targets' => $morphTargets,
                    'stats' => $stats,
                    'facts_digest' => $digest,
                ];
                $current = $held[$name] ?? null;
                if ($current === null) {
                    $row = ['game_id' => $gameId, 'unit_name' => $name, ...$facts, 'source_version' => $release];
                    self::refuse('game_unit', $row);
                    self::refuse('game_unit_revision', ['version' => $release, ...$facts]);
                    $id = DB::table('game_unit')->insertGetId([
                        ...self::stored('game_unit', $row),
                        'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $current = (object) ['id' => $id, 'unit_name' => $name, 'facts_digest' => $digest];
                    $held[$name] = $current;
                    $outcome = 'accepted';
                } elseif ($digest !== null && $current->facts_digest !== $digest) {
                    // Different facts under one identity: the newest reading wins.
                    $changes = [...$facts, 'source_version' => $release];
                    self::refuse('game_unit', $changes);
                    self::refuse('game_unit_revision', ['version' => $release, ...$facts]);
                    DB::table('game_unit')->where('id', $current->id)->update([
                        ...self::stored('game_unit', $changes),
                        'removed_at' => null, 'last_seen_at' => $now, 'updated_at' => $now,
                    ]);
                    $current->facts_digest = $digest;
                    $outcome = 'accepted';
                } elseif ($complete) {
                    DB::table('game_unit')->where('id', $current->id)->update(['removed_at' => null, 'last_seen_at' => $now, 'updated_at' => $now]);
                } else {
                    DB::table('game_unit')->where('id', $current->id)->update(['last_seen_at' => $now, 'updated_at' => $now]);
                }

                // The revision for this release: replaced when the facts were
                // accepted, written only if missing otherwise.
                $revision = ['unit_id' => $current->id, 'version' => $release, ...$facts];
                $exists = isset($revisions[(int) $current->id]);
                if ($outcome === 'accepted') {
                    self::refuse('game_unit_revision', $revision);
                    if ($exists) {
                        DB::table('game_unit_revision')->where('unit_id', $current->id)->where('version', $release)
                            ->update(self::stored('game_unit_revision', $facts));
                    } else {
                        DB::table('game_unit_revision')->insert([...self::stored('game_unit_revision', $revision), 'recorded_at' => $now]);
                    }
                } elseif (! $exists) {
                    self::refuse('game_unit_revision', $revision);
                    DB::table('game_unit_revision')->insert([...self::stored('game_unit_revision', $revision), 'recorded_at' => $now]);
                    $outcome = 'recorded';
                } else {
                    $outcome ??= 'unchanged';
                }
                $revisions[(int) $current->id] = true;
            } catch (LocalException $e) {
                $outcome = 'refused';
                $said = $e->getMessage();
                $refused = true;
            }
            $results[] = ['kind' => 'unit', 'name' => $name, 'outcome' => $outcome, 'said' => $said];
        }

        return [$results, $refused];
    }

    /** Sources a client offered, held for the owner. Answers how many are newly held. */
    private static function offerGameDownloadSources(?string $shortname, mixed $sources, ?string $offeredBy): int
    {
        if (! is_array($sources)) {
            return 0;
        }

        return DB::transaction(function () use ($shortname, $sources, $offeredBy) {
            $gameId = DB::table('game')->where('shortname', $shortname)->value('id');
            if ($gameId === null) {
                return 0;
            }
            $now = Values::now();
            $held = 0;
            foreach ($sources as $entry) {
                $kind = self::text($entry, 'kind');
                $value = self::text($entry, 'value');
                $listed = DB::table('game_download_source')->where('game_id', $gameId)->where('kind', $kind)->where('value', $value)->exists();
                if ($listed) {
                    continue;
                }
                try {
                    $row = [
                        'game_id' => $gameId, 'kind' => $kind, 'value' => $value, 'asset' => self::text($entry, 'asset'),
                        'filename' => self::text($entry, 'filename'), 'offered_by' => $offeredBy, 'state' => 'held',
                        'decided_at' => null, 'created_at' => $now,
                    ];
                    self::refuse('game_download_offer', $row);
                    $waiting = DB::table('game_download_offer')->where('game_id', $gameId)->where('kind', $kind)
                        ->where('value', $value)->where('state', 'held')->lockForUpdate()->exists();
                    if (! $waiting) {
                        DB::table('game_download_offer')->insert(self::stored('game_download_offer', $row));
                        $held++;
                    }
                } catch (LocalException) {
                    // A source the table refuses is dropped, as the SQL did.
                }
            }

            return $held;
        });
    }

    /** An owner or moderator accepts or declines a held offer. */
    private static function decideGameDownloadOffer(Caller $caller, int $offerId, bool $accept): bool
    {
        $userId = $caller->userId();
        if ($userId === null) {
            throw new LocalException('Sign in to decide a download offer.', 403, '42501');
        }

        return DB::transaction(function () use ($caller, $userId, $offerId, $accept) {
            $offer = DB::table('game_download_offer')->where('id', $offerId)->where('state', 'held')->lockForUpdate()->first();
            if ($offer === null) {
                return false;
            }
            $owner = DB::table('game')->where('id', $offer->game_id)->where('owner_user_id', $userId)->exists();
            if (! $owner && ! $caller->isModerator()) {
                throw new LocalException("Only the game's owner or a moderator decides a download offer.", 403, '42501');
            }
            if ($accept) {
                $listed = DB::table('game_download_source')->where('game_id', $offer->game_id)
                    ->where('kind', $offer->kind)->where('value', $offer->value)->exists();
                if (! $listed) {
                    $last = DB::table('game_download_source')->where('game_id', $offer->game_id)->max('sort_order');
                    DB::table('game_download_source')->insert([
                        'game_id' => $offer->game_id, 'kind' => $offer->kind, 'value' => $offer->value,
                        'asset' => $offer->asset, 'filename' => $offer->filename,
                        'sort_order' => $last === null ? 0 : (int) $last + 1,
                    ]);
                }
            }
            DB::table('game_download_offer')->where('id', $offer->id)->update([
                'state' => $accept ? 'accepted' : 'declined', 'decided_by' => $userId, 'decided_at' => Values::now(),
            ]);

            return true;
        });
    }

    /**
     * Up to six random live units of each game named, for the listing's
     * faces. Runs as the caller, so a hidden game gives none.
     *
     * @param  list<mixed>  $shortnames
     * @return list<array{shortname: string, unit_name: string, full_name: ?string, faction_key: ?string}>
     */
    private static function gameSampleUnits(Caller $caller, array $shortnames, mixed $count): array
    {
        $limit = min(max((int) ($count ?? 0), 0), 6);
        if ($limit === 0 || $shortnames === []) {
            return [];
        }
        $games = DB::table('game as g')->whereIn('g.shortname', array_map('strval', $shortnames));
        Policies::read($caller, $games, 'game', 'g');
        $out = [];
        foreach ($games->get(['g.id', 'g.shortname']) as $game) {
            $units = DB::table('game_unit')->where('game_id', $game->id)->whereNull('removed_at')
                ->inRandomOrder()->limit($limit)->get(['unit_name', 'full_name', 'faction_key']);
            foreach ($units as $unit) {
                $out[] = ['shortname' => $game->shortname, 'unit_name' => $unit->unit_name, 'full_name' => $unit->full_name, 'faction_key' => $unit->faction_key];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function refuse(string $table, array $row): void
    {
        $decoded = json_decode(json_encode($row, JSON_THROW_ON_ERROR), true);
        if (($violation = Tables::violation($table, $decoded)) !== null) {
            throw $violation;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function stored(string $table, array $row): array
    {
        $types = Tables::columns($table);
        $out = [];
        foreach ($row as $column => $value) {
            $out[$column] = Values::write($types[$column], $value);
        }

        return $out;
    }

    /** `->>`: the member as text, or null when absent or JSON null. */
    private static function text(mixed $object, string $key): ?string
    {
        if (! $object instanceof stdClass || ! property_exists($object, $key)) {
            return null;
        }
        $value = $object->{$key};

        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => json_encode($value),
            default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    private static function has(mixed $object, string $key): bool
    {
        return $object instanceof stdClass && property_exists($object, $key);
    }

    private static function property(mixed $object, string $key): mixed
    {
        return self::has($object, $key) ? $object->{$key} : null;
    }

    /**
     * `jsonb_array_elements_text`: each element as text. Null gives none,
     * anything else that is not an array is refused.
     *
     * @return list<?string>
     */
    private static function texts(mixed $array): array
    {
        if ($array === null) {
            return [];
        }
        if (! is_array($array)) {
            throw new LocalException('cannot extract elements from a scalar', 400, '22023');
        }

        return array_map(fn ($value) => self::text((object) ['v' => $value], 'v'), $array);
    }

    /** `::boolean`, with absent meaning false. */
    private static function boolean(?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new LocalException('invalid input syntax for type boolean: "'.$value.'"', 400, '22P02');
        }

        return $parsed;
    }
}

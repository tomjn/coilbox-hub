<?php

namespace App\Domain\Assets;

use App\Supabase\Client;
use App\Supabase\Query;
use App\Supabase\SupabaseException;
use Closure;

/**
 * Everything an upload is refused for, and the row it writes when it is not,
 * `lib/assets/upload.ts` in the Next app.
 *
 * A rejected upload must cost nothing, so every check runs before the write,
 * cheapest first. The database checks are read in a fixed order so a request
 * that trips two limits always hears about the same one.
 *
 * The licence is not a gate on upload. Moderation and reporting are.
 */
final class Upload
{
    /** The backstop for a class with no byte cap of its own (the metal and type overlays). */
    public static function maxObjectBytes(): int
    {
        return Asset::vocabulary()['maxObjectBytes'];
    }

    /** A sixteenth of the 1 GB staging bucket, per account, promoted rows included. */
    public const ACCOUNT_STORAGE_QUOTA_BYTES = 64 * 1024 * 1024;

    /** Renders are the one class that grows without bound (units times angles), so they are capped per unit. */
    public const UNIT_RENDER_CEILING = 8;

    /**
     * Uploads per account per subject per hour, counted on `seen_at`. The
     * subject is a game for a unit, a game's logos for a faction, and maps as a
     * whole for a map. Insurance against a looping client.
     */
    public const SUBJECT_UPLOADS_PER_HOUR = 500;

    private const EXISTING_COLUMNS = 'id, source_hash, source_archive, uploaded_by, moderation, bytes, bytes_missing_at';

    private const FACTIONS_UNAVAILABLE = ['ok' => false, 'error' => "The game's factions could not be read just now. Try again shortly.", 'status' => 503];

    private const QUOTA_UNAVAILABLE = ['ok' => false, 'error' => 'The upload quotas could not be read just now. Try again shortly.', 'status' => 503];

    /**
     * Everything that has to be true before a byte is written, and the path
     * the bytes go to. `$admin` must be the secret key client, because every
     * question here is about rows the read policy hides. `$hash` is the hub's
     * own. `$mayReplaceAnyAsset` is asked only when the identity belongs to
     * another account.
     *
     * Answers `['ok' => true, 'path', 'replacing' => id|null, 'conflict' => ?array]`
     * or `['ok' => false, 'error', 'status', 'conflict' => ?array]`.
     *
     * @param  array<string, mixed>  $declaration
     * @param  (Closure(): bool)|null  $mayReplaceAnyAsset
     * @return array<string, mixed>
     */
    public static function check(Client $admin, string $userId, array $declaration, string $hash, ?Closure $mayReplaceAnyAsset = null): array
    {
        $identity = $declaration['identity'];
        $mime = $declaration['mime'];
        $bytes = $declaration['bytes'];

        if (! AssetPath::isAssetMime($mime)) {
            return ['ok' => false, 'error' => '`mime` must be one of '.implode(', ', array_keys(AssetPath::MIME_EXTENSIONS)).'.', 'status' => 415];
        }

        $maxBytes = Caps::forVariant($identity['variant'])['maxBytes'] ?? self::maxObjectBytes();
        if ($bytes > $maxBytes) {
            return ['ok' => false, 'error' => 'A "'.$identity['variant'].'" may be at most '.$maxBytes.' bytes. That one declares '.$bytes.'.', 'status' => 413];
        }

        $path = AssetPath::objectPath($identity, $hash, $mime);
        if ($path === null) {
            return [
                'ok' => false,
                'error' => '`game` and `variant` have to be storable as path segments: letters, digits, dots, dashes and underscores, and a variant may separate segments with a colon.',
                'status' => 400,
            ];
        }

        // A logo is for a side the game reports. A key the hub has never heard of is a typo or a probe.
        if ($identity['keyedOn'] === 'faction') {
            $reported = self::count(fn () => $admin->from('game')
                ->select('id, game_faction!inner(key)', 'exact')
                ->eq('shortname', $identity['game'])
                ->eq('game_faction.key', $identity['factionKey']));
            if ($reported === null) {
                return self::FACTIONS_UNAVAILABLE;
            }
            if ($reported === 0) {
                return ['ok' => false, 'error' => 'The game "'.$identity['game'].'" has no faction with the key "'.$identity['factionKey'].'".', 'status' => 404];
            }
        }

        $since = now()->utc()->subHour()->format('Y-m-d\TH:i:s.v\Z');

        // The Next app issues these four together and reads them in this order.
        $existing = self::fetchExisting($admin, $identity);
        $unitRenders = $identity['keyedOn'] === 'unit' && str_starts_with($identity['variant'], Asset::unitRenderVariantPrefix())
            ? self::count(fn () => $admin->from('asset')
                ->select('id', 'exact')
                ->eq('game', $identity['game'])
                ->eq('unit_name', $identity['unitName'])
                ->like('variant', Asset::unitRenderVariantPrefix().'%'))
            : 0;
        try {
            $accountBytes = $admin->rpc('account_asset_bytes', ['account' => $userId]);
        } catch (SupabaseException) {
            $accountBytes = null;
        }
        $recent = self::count(fn () => self::recentUploads($admin, $userId, $identity, $since));

        if ($existing === false) {
            return self::QUOTA_UNAVAILABLE;
        }
        $replacing = $existing;

        // Rides along on a refusal for another account's row and on acceptance, and on nothing in between.
        $conflict = $replacing !== null ? SourceConflict::detect($replacing, $declaration, $userId) : null;

        $takingOver = $replacing !== null && $replacing['uploaded_by'] !== $userId;

        if ($replacing !== null) {
            // Letting anyone replace anyone's asset would let one account de-publish the corpus.
            if ($takingOver && ! ($mayReplaceAnyAsset !== null && $mayReplaceAnyAsset())) {
                return ['ok' => false, 'error' => 'Another account uploaded the asset with that identity, so it cannot be replaced.', 'status' => 409, 'conflict' => $conflict];
            }
            // A rejection is a moderator's call, and an upload is not the way to argue with it.
            if ($replacing['moderation'] === 'rejected') {
                return ['ok' => false, 'error' => 'That asset was rejected, and a rejection is not something an upload can undo.', 'status' => 409];
            }
            // The same source bytes, unless the hub has lost them.
            if ($replacing['source_hash'] === $declaration['sourceHash'] && $replacing['bytes_missing_at'] === null) {
                return [
                    'ok' => false,
                    'error' => 'The hub already holds that asset with the same `source_hash`. Check `/api/v1/assets/have` before uploading.',
                    'status' => 409,
                ];
            }
        }

        if ($unitRenders === null) {
            return self::QUOTA_UNAVAILABLE;
        }
        // A replacement stores no new render.
        if ($unitRenders + ($replacing !== null ? 0 : 1) > self::UNIT_RENDER_CEILING) {
            return ['ok' => false, 'error' => 'That unit already has '.$unitRenders.' stored renders, which is the ceiling of '.self::UNIT_RENDER_CEILING.'.', 'status' => 409];
        }

        if (! is_int($accountBytes) && ! is_float($accountBytes)) {
            return self::QUOTA_UNAVAILABLE;
        }
        // The superseded row's bytes come off the total, except on a takeover, where they were never this account's.
        $superseded = $replacing !== null && ! $takingOver ? $replacing['bytes'] : 0;
        if ($accountBytes - $superseded + $bytes > self::ACCOUNT_STORAGE_QUOTA_BYTES) {
            return ['ok' => false, 'error' => 'That upload would put this account over its '.self::ACCOUNT_STORAGE_QUOTA_BYTES.' byte storage quota.', 'status' => 413];
        }

        if ($recent === null) {
            return self::QUOTA_UNAVAILABLE;
        }
        if ($recent >= self::SUBJECT_UPLOADS_PER_HOUR) {
            return ['ok' => false, 'error' => 'Too many uploads for that subject in the last hour, which is capped at '.self::SUBJECT_UPLOADS_PER_HOUR.'. Try again later.', 'status' => 429];
        }

        return ['ok' => true, 'path' => $path, 'replacing' => $replacing['id'] ?? null, 'conflict' => $conflict];
    }

    /**
     * Whether the uploader may skip the queue: `can_publish_unreviewed` or
     * `can_moderate`. Asked with the uploader's own client, because
     * `has_capability()` answers for the caller. False when unanswerable.
     */
    public static function skipsQueue(Client $user): bool
    {
        foreach (['can_publish_unreviewed', 'can_moderate'] as $capability) {
            if (self::hasCapability($user, $capability)) {
                return true;
            }
        }

        return false;
    }

    /** `can_replace_any_asset` alone. A moderator does not get it by being one. */
    public static function mayReplaceAnyAsset(Client $user): bool
    {
        return self::hasCapability($user, 'can_replace_any_asset');
    }

    /**
     * Write the row, replacing `$replacing` in place when a newer archive
     * changed the bytes. A replacement goes back to pending, back to the
     * bucket tier and loses its approval source, unless the uploader skips the
     * queue. Answers the row's id, or null when nothing was written.
     *
     * @param  array<string, mixed>  $declaration
     * @param  array{width: int, height: int}  $measured
     */
    public static function write(Client $admin, string $userId, array $declaration, string $hash, string $path, array $measured, ?string $replacing, bool $skipQueue): ?string
    {
        $identity = $declaration['identity'];
        $columns = [
            'source_hash' => $declaration['sourceHash'],
            'hash' => $hash,
            'encode_profile' => $declaration['encodeProfile'],
            'path' => $path,
            'origin' => $declaration['origin'],
            'tier' => 'bucket',
            'mime' => $declaration['mime'],
            'bytes' => $declaration['bytes'],
            'width' => $measured['width'],
            'height' => $measured['height'],
            'map_width' => $declaration['mapWidth'],
            'map_height' => $declaration['mapHeight'],
            'world_height_min' => $declaration['worldHeightMin'],
            'world_height_max' => $declaration['worldHeightMax'],
            'source_archive' => $declaration['sourceArchive'],
        ];
        $moderation = $skipQueue
            ? ['moderation' => 'approved', 'approval_source' => 'bypass']
            : ['moderation' => 'pending', 'approval_source' => null];

        try {
            if ($replacing !== null) {
                $admin->from('asset')->update([
                    ...$columns,
                    // Not defaulted the way an insert's is, so the hourly limit counts this write.
                    'seen_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                    'promoted_at' => null,
                    'bytes_missing_at' => null,
                    ...$moderation,
                    'uploaded_by' => $userId,
                ])->eq('id', $replacing)->execute();

                return $replacing;
            }

            $rows = $admin->from('asset')->insert([
                'game' => $identity['keyedOn'] === 'map' ? null : $identity['game'],
                'unit_name' => $identity['keyedOn'] === 'unit' ? $identity['unitName'] : null,
                'map_name' => $identity['keyedOn'] === 'map' ? $identity['mapName'] : null,
                'faction_key' => $identity['keyedOn'] === 'faction' ? $identity['factionKey'] : null,
                'variant' => $identity['variant'],
                ...$columns,
                ...$moderation,
                'uploaded_by' => $userId,
            ])->select('id')->get();

            return count($rows) === 1 && isset($rows[0]['id']) ? (string) $rows[0]['id'] : null;
        } catch (SupabaseException) {
            return null;
        }
    }

    /**
     * The row this identity already has, null when none, false when the read
     * failed. Both identity indexes are unique, so a second row is an error.
     *
     * @param  array<string, string>  $identity
     * @return array<string, mixed>|null|false
     */
    private static function fetchExisting(Client $admin, array $identity): array|null|false
    {
        try {
            return $admin->from('asset')->select(self::EXISTING_COLUMNS)->or(Have::identityFilter($identity))->maybeSingle();
        } catch (SupabaseException) {
            return false;
        }
    }

    /** @param array<string, string> $identity */
    private static function recentUploads(Client $admin, string $userId, array $identity, string $since): Query
    {
        $mine = $admin->from('asset')->select('id', 'exact')->eq('uploaded_by', $userId);

        return match ($identity['keyedOn']) {
            'unit' => $mine->eq('game', $identity['game'])->gte('seen_at', $since),
            'faction' => $mine->eq('game', $identity['game'])->not('faction_key', 'is', null)->gte('seen_at', $since),
            'map' => $mine->not('map_name', 'is', null)->gte('seen_at', $since),
        };
    }

    /**
     * A count, or null when the query failed. Null is not zero: a failed quota
     * read must not let every limit through. One row is fetched, since only the
     * total in the `Content-Range` header is wanted.
     *
     * @param  Closure(): Query  $query
     */
    private static function count(Closure $query): ?int
    {
        try {
            return $query()->limit(1)->getWithCount()['count'] ?? 0;
        } catch (SupabaseException) {
            return null;
        }
    }

    private static function hasCapability(Client $user, string $capability): bool
    {
        try {
            return $user->rpc('has_capability', ['capability' => $capability]) === true;
        } catch (SupabaseException) {
            return false;
        }
    }
}

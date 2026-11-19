<?php

namespace App\Domain\Mirrors;

use App\Supabase\Client;
use App\Supabase\SupabaseException;
use App\Support\HubCache;

/**
 * Adding, removing and switching on the hub's mirrors from the moderation
 * page. The database decides who may, see 20261118120000_moderation_mirrors.sql.
 * Any change marks the catalogue stale, and the next catalogue request signs
 * a new one, so mirrors learn of it on their next sync.
 */
final class Registry
{
    private const MESSAGES = [
        'added' => 'Added. It gets no downloads until its first report passes the hub\'s check.',
        'removed' => 'Removed. Mirrors drop it from the catalogue on their next sync.',
        'switched_off' => 'Uploads went through this mirror, so the hub keeps its record and switched it off instead. It gets no downloads or uploads.',
        'switched_on' => 'Switched on. It gets no downloads until its next report passes the hub\'s check.',
        'not_allowed' => 'You cannot change mirrors.',
        'not_found' => 'That mirror no longer exists.',
        'bad_id' => 'The ID can only use lowercase letters, digits and hyphens, and must start with a letter or digit. At most 64 characters.',
        'bad_name' => 'The name must be between 1 and 128 characters.',
        'bad_url' => 'The address must start with https:// and have no query string or # part.',
        'bad_key' => 'The public key must be the 43 character key the mirror\'s keygen printed.',
        'id_taken' => 'Another mirror already has that ID.',
    ];

    /** The page's data, or null when it could not be read. */
    public static function list(Client $client): ?array
    {
        try {
            $data = $client->rpc('moderation_mirrors');
        } catch (SupabaseException) {
            return null;
        }

        return is_array($data) && is_array($data['mirrors'] ?? null) ? $data : null;
    }

    /** @return array{ok: bool, message: string} */
    public static function add(Client $client, string $id, string $name, string $baseUrl, string $publicKey): array
    {
        return self::answer($client, 'moderation_add_mirror', [
            'p_id' => trim($id),
            'p_name' => trim($name),
            // The table wants no trailing slash. A pasted address often has one.
            'p_base_url' => rtrim(trim($baseUrl), '/'),
            'p_public_key' => trim($publicKey),
        ], 'added', 'It could not be added just now. Try again.');
    }

    /** @return array{ok: bool, message: string} */
    public static function remove(Client $client, string $id): array
    {
        return self::answer($client, 'moderation_remove_mirror', ['p_id' => $id], ['removed', 'switched_off'], 'It could not be removed just now. Try again.');
    }

    /** @return array{ok: bool, message: string} */
    public static function enable(Client $client, string $id): array
    {
        return self::answer($client, 'moderation_enable_mirror', ['p_id' => $id], 'switched_on', 'It could not be switched on just now. Try again.');
    }

    /**
     * One status word for a mirror, from what its reports have shown.
     *
     * @return array{label: string, tone: string}
     */
    public static function health(array $mirror): array
    {
        if (! ($mirror['enabled'] ?? false)) {
            return ['label' => 'Switched off', 'tone' => 'off'];
        }
        if (($mirror['last_report_at'] ?? null) === null) {
            return ['label' => 'Waiting for its first report', 'tone' => 'waiting'];
        }

        return ($mirror['healthy'] ?? false)
            ? ['label' => 'Healthy', 'tone' => 'good']
            : ['label' => 'Failed its last check', 'tone' => 'bad'];
    }

    /**
     * @param  string|list<string>  $success  The outcomes that count as done.
     * @return array{ok: bool, message: string}
     */
    private static function answer(Client $client, string $function, array $args, string|array $success, string $failed): array
    {
        try {
            $outcome = $client->rpc($function, $args);
        } catch (SupabaseException) {
            $outcome = null;
        }
        $ok = in_array($outcome, (array) $success, true);

        if ($ok) {
            // Map and game pages are cached and list the mirrors that hold each
            // download. Unit pages list none, so the narrower tag spares them.
            HubCache::invalidate([HubCache::MAPS, HubCache::DOWNLOADS]);
        }

        return [
            'ok' => $ok,
            'message' => is_string($outcome) && isset(self::MESSAGES[$outcome]) ? self::MESSAGES[$outcome] : $failed,
        ];
    }
}

<?php

namespace App\Supabase\Local;

use App\Auth\GoTrue;
use App\Auth\HubAccounts;
use App\Domain\Points\PicturePoints;
use App\Supabase\Supabase;
use Illuminate\Support\Facades\DB;

/**
 * The triggers on Supabase's `public.asset`, run around the local store's
 * inserts and updates (#598). Each method names the trigger and function it
 * stands for. They run in Postgres's order: before triggers, then after
 * triggers, each set by name.
 *
 * Rows are in stored form, `$old` null on an insert.
 */
final class AssetTriggers
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>  $new
     * @param  list<string>  $set  the columns the statement sets, for `update of`
     */
    public static function before(?array $old, array $new, array $set): void
    {
        // asset_refuse_reserved_staged_path: before insert or update of path,
        // tier, when the row is in the bucket tier.
        if (($old === null || array_intersect($set, ['path', 'tier']) !== []) && ($new['tier'] ?? null) === 'bucket') {
            if (DB::table('staged_object_deletion')->where('path', $new['path'])->lockForUpdate()->exists()) {
                throw new LocalException('staged object '.$new['path'].' is being deleted, so it cannot be claimed until that finishes', 409, '55006');
            }
        }
        if ($old === null) {
            return;
        }
        // asset_refuse_unauthorised_takeover: before update of uploaded_by.
        if (in_array('uploaded_by', $set, true) && ($new['uploaded_by'] ?? null) !== null
            && $new['uploaded_by'] !== ($old['uploaded_by'] ?? null) && ! self::mayReplaceAnyAsset((string) $new['uploaded_by'])) {
            throw new LocalException("replacing another account's picture needs can_replace_any_asset", 403, '42501');
        }
        // asset_safety_rejection_is_final.
        if ($old['moderation'] === 'rejected' && $old['rejection_kind'] === 'safety') {
            foreach (['moderation', 'rejection_kind', 'path', 'tier', 'hash', 'source_hash'] as $column) {
                if (self::distinct($old[$column] ?? null, $new[$column] ?? null)) {
                    throw new LocalException('asset '.$old['id'].' was rejected on safety grounds, which is final', 400, '23514');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>  $new
     */
    public static function after(Caller $caller, ?array $old, array $new): void
    {
        // asset_purge_upload_ip: after update, once approved.
        if ($old !== null && $new['moderation'] === 'approved' && $old['moderation'] !== 'approved') {
            DB::table('asset_upload_ip')->where('asset_id', $new['id'])->delete();
        }
        self::recordEvent($caller, $old, $new);
        if ($old !== null) {
            self::recordWithdrawal($old, $new);
        }
        self::syncFactionLogo($old, $new);
        PicturePoints::changed($old, $new);
    }

    /**
     * `public.record_asset_event()`: a replacement by another account, and
     * every change of moderation state worth a row.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>  $new
     */
    private static function recordEvent(Caller $caller, ?array $old, array $new): void
    {
        $now = Values::now();
        if ($old !== null && ($new['uploaded_by'] ?? null) !== null && self::distinct($new['uploaded_by'], $old['uploaded_by'] ?? null)) {
            DB::table('asset_event')->insert([
                'asset_id' => $new['id'], 'action' => 'replaced', 'actor' => $new['uploaded_by'], 'uploader' => $old['uploaded_by'], 'at' => $now,
            ]);
        }
        if ($old !== null
            && ! self::distinct($new['moderation'], $old['moderation'])
            && ! self::distinct($new['rejection_kind'] ?? null, $old['rejection_kind'] ?? null)
            && ! ($new['moderation'] === 'approved' && self::distinct($new['hash'], $old['hash']))) {
            return;
        }
        $recorded = match ($new['moderation']) {
            'approved' => match ($new['approval_source'] ?? null) {
                'seed' => 'seeded',
                'bypass' => 'bypassed',
                default => 'approved',
            },
            'rejected' => 'rejected',
            // Pending is only worth a row when something came back to it.
            default => $old !== null ? 'returned' : null,
        };
        if ($recorded === null) {
            return;
        }
        $displayName = null;
        $providerName = null;
        // Who uploaded it, by name, on a safety rejection only (#146).
        if ($recorded === 'rejected' && ($new['rejection_kind'] ?? null) === 'safety' && ($new['uploaded_by'] ?? null) !== null) {
            [$displayName, $providerName] = self::uploaderNames((string) $new['uploaded_by']);
        }
        DB::table('asset_event')->insert([
            'asset_id' => $new['id'],
            'action' => $recorded,
            'rejection_kind' => $new['rejection_kind'] ?? null,
            'actor' => $caller->userId() ?? ($new['uploaded_by'] ?? null),
            'uploader' => $new['uploaded_by'] ?? null,
            'at' => $now,
            'uploader_display_name' => $displayName,
            'uploader_provider_name' => $providerName,
        ]);
    }

    /**
     * `public.record_asset_withdrawal()`: a promoted picture rejected on
     * safety grounds goes on the takedown list, once.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private static function recordWithdrawal(array $old, array $new): void
    {
        if ($new['moderation'] !== 'rejected' || ($new['rejection_kind'] ?? null) !== 'safety') {
            return;
        }
        if ($old['moderation'] === 'rejected' && ($old['rejection_kind'] ?? null) === 'safety') {
            return;
        }
        if ($new['tier'] !== 'static') {
            return;
        }
        if (! DB::table('asset_withdrawal')->where('asset_id', $new['id'])->exists()) {
            DB::table('asset_withdrawal')->insert(['asset_id' => $new['id'], 'path' => $new['path'], 'at' => Values::now()]);
        }
    }

    /**
     * `public.sync_faction_logo()`: a side's logo follows its approved,
     * promoted picture and is cleared when that picture is rejected. The sides
     * are wherever the game catalogue is, so this goes through the client.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>  $new
     */
    private static function syncFactionLogo(?array $old, array $new): void
    {
        if (($new['faction_key'] ?? null) === null) {
            return;
        }
        $wasLive = $old !== null && $old['moderation'] === 'approved' && $old['tier'] === 'static';
        $wasRejected = $old !== null && $old['moderation'] === 'rejected';
        if ($new['moderation'] === 'approved' && $new['tier'] === 'static' && ! $wasLive) {
            $values = ['logo_path' => $new['path'], 'logo_hash' => $new['hash']];
            $samePath = null;
        } elseif ($new['moderation'] === 'rejected' && ! $wasRejected) {
            $values = ['logo_path' => null, 'logo_hash' => null];
            $samePath = $new['path'];
        } else {
            return;
        }
        $admin = Supabase::admin();
        $game = $admin->from('game')->select('id')->eq('shortname', $new['game'])->maybeSingle();
        if ($game === null) {
            return;
        }
        $update = $admin->from('game_faction')->update($values)->eq('game_id', $game['id'])->eq('key', $new['faction_key']);
        if ($samePath !== null) {
            $update->eq('logo_path', $samePath);
        }
        $update->execute();
    }

    /** The trigger's check, asked of whichever database holds `user_capability`. */
    private static function mayReplaceAnyAsset(string $userId): bool
    {
        return Supabase::admin()->from('user_capability')->select('user_id')
            ->eq('user_id', $userId)->eq('capability', 'can_replace_any_asset')->limit(1)->get() !== [];
    }

    /**
     * The uploader's display name, from whichever database holds profiles,
     * and the name their sign in provider gave, from whichever runs sign in.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function uploaderNames(string $userId): array
    {
        $profile = Supabase::admin()->from('profile')->select('display_name')->eq('user_id', $userId)->maybeSingle();
        $metadata = SignInStore::state() === SignInStore::MARIADB
            ? (HubAccounts::user(strtolower($userId))['user_metadata'] ?? [])
            : (GoTrue::adminUserMetadata($userId) ?? []);
        $provider = null;
        foreach (['preferred_username', 'user_name', 'full_name', 'name'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && Tables::btrim($value) !== '') {
                $provider = Tables::btrim($value);
                break;
            }
        }

        return [$profile['display_name'] ?? null, $provider];
    }

    /** `is distinct from` on stored values. */
    private static function distinct(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a !== $b;
        }

        return (string) $a !== (string) $b;
    }
}

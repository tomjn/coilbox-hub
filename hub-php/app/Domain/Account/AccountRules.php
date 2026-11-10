<?php

namespace App\Domain\Account;

use App\Auth\GoTrue;

/** lib/account/displayName.ts, confirmDeletion.ts and signInMethods.ts. */
final class AccountRules
{
    public const DISPLAY_NAME_MIN = 2;

    public const DISPLAY_NAME_MAX = 40;

    public const LAST_METHOD_REASON = 'This is your only way to sign in, so it cannot be removed. Link another one first.';

    /** @return array{name: string}|array{error: string} */
    public static function checkDisplayName(mixed $raw): array
    {
        $name = is_string($raw) ? (string) preg_replace('/\s+/u', ' ', trim($raw)) : '';
        $length = mb_strlen($name);
        if ($length < self::DISPLAY_NAME_MIN) {
            return ['error' => 'Your display name must be at least '.self::DISPLAY_NAME_MIN.' characters.'];
        }
        if ($length > self::DISPLAY_NAME_MAX) {
            return ['error' => 'Your display name must be at most '.self::DISPLAY_NAME_MAX.' characters.'];
        }

        return ['name' => $name];
    }

    public static function renameErrorMessage(?string $code): string
    {
        return match ($code) {
            '23505' => 'That name is already in use, in any mix of capital and small letters. Choose another.',
            '23514' => 'Your display name must be between '.self::DISPLAY_NAME_MIN.' and '.self::DISPLAY_NAME_MAX.' characters.',
            default => 'Could not save your display name. Try again.',
        };
    }

    public static function confirmsDeletion(mixed $typed, string $expected): bool
    {
        $wanted = trim($expected);

        return is_string($typed) && $wanted !== '' && trim($typed) === $wanted;
    }

    /** @param array<string, mixed> $identity */
    public static function identityAccount(array $identity): ?string
    {
        $data = is_array($identity['identity_data'] ?? null) ? $identity['identity_data'] : [];
        foreach (['preferred_username', 'user_name', 'full_name', 'name', 'email'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $identities
     * @return list<array{identityId: string, provider: string, label: string, account: string|null}>
     */
    public static function signInMethods(array $identities): array
    {
        return array_map(fn (array $identity) => [
            'identityId' => (string) $identity['identity_id'],
            'provider' => (string) $identity['provider'],
            'label' => GoTrue::PROVIDERS[$identity['provider']] ?? (string) $identity['provider'],
            'account' => self::identityAccount($identity),
        ], $identities);
    }

    /**
     * @param  list<array<string, mixed>>  $identities
     * @return array<string, string> Provider id to label, for the providers not linked yet.
     */
    public static function linkableProviders(array $identities): array
    {
        $linked = array_column($identities, 'provider');

        return array_filter(GoTrue::PROVIDERS, fn ($label, $id) => ! in_array($id, $linked, true), ARRAY_FILTER_USE_BOTH);
    }

    public static function linkFailureMessage(string $provider, ?string $reason): string
    {
        $label = GoTrue::PROVIDERS[$provider] ?? $provider;
        if ($reason === 'identity_already_exists') {
            return "That {$label} account is already linked to another account on the hub. Sign in with it to use that account, or link a different {$label} account.";
        }

        return "Could not link your {$label} account. Try again.";
    }

    /** Digits only, spaces removed. Null for anything else. */
    public static function readCode(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $code = (string) preg_replace('/\s+/', '', $raw);

        return preg_match('/^\d+$/', $code) ? $code : null;
    }
}

<?php

namespace App\Supabase\Local;

use App\Supabase\Client;
use App\Supabase\Supabase;

/**
 * Who is asking, as row level security would see them: the role, the user id
 * behind a token and whether they moderate. The last two are worked out only
 * when a rule needs them. Who a token belongs to is asked of Supabase Auth.
 */
final class Caller
{
    private ?string $userId;

    private bool $userResolved;

    private ?bool $moderator = null;

    /** Inside a `security definer` function: past every privilege and policy, still knowing who called. */
    private bool $definer = false;

    public function __construct(public readonly Client $client)
    {
        $this->userId = $client->knownUserId();
        $this->userResolved = $this->userId !== null || $client->accessToken() === null;
    }

    public function role(): string
    {
        return $this->definer ? 'service_role' : $this->client->role();
    }

    /**
     * This caller as a `security definer` function sees them: the function's
     * writes pass every grant and policy, and `auth.uid()` is still theirs.
     */
    public function definer(): self
    {
        $this->userId();
        $definer = clone $this;
        $definer->definer = true;

        return $definer;
    }

    public function isService(): bool
    {
        return $this->role() === 'service_role';
    }

    /** `auth.uid()`: null for anon and the service role. */
    public function userId(): ?string
    {
        if (! $this->userResolved) {
            $this->userResolved = true;
            $user = Supabase::userForToken((string) $this->client->accessToken());
            if ($user === null) {
                throw new LocalException('JWT expired', 401, 'PGRST301');
            }
            $this->userId = (string) $user['id'];
        }

        return $this->userId;
    }

    /** `public.is_moderator()` for this caller, asked of whichever database holds the capabilities. */
    public function isModerator(): bool
    {
        if ($this->moderator === null) {
            $this->moderator = $this->role() === 'authenticated' && (ItemStore::state() === ItemStore::MARIADB
                ? ItemFunctions::capabilityAccess($this, 'can_moderate') === 'granted'
                : $this->client->remote()->rpc('is_moderator') === true);
        }

        return $this->moderator;
    }
}

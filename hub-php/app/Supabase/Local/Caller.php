<?php

namespace App\Supabase\Local;

use App\Supabase\Client;
use App\Supabase\Supabase;

/**
 * Who is asking, as row level security would see them: the role, the user id
 * behind a token and whether they moderate. The last two are asked of
 * Supabase only when a rule needs them, because profiles and capabilities
 * have not moved yet.
 */
final class Caller
{
    private ?string $userId;

    private bool $userResolved;

    private ?bool $moderator = null;

    public function __construct(public readonly Client $client)
    {
        $this->userId = $client->knownUserId();
        $this->userResolved = $this->userId !== null || $client->accessToken() === null;
    }

    public function role(): string
    {
        return $this->client->role();
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

    /** `public.is_moderator()` for this caller. */
    public function isModerator(): bool
    {
        if ($this->moderator === null) {
            $this->moderator = $this->role() === 'authenticated'
                && $this->client->remote()->rpc('is_moderator') === true;
        }

        return $this->moderator;
    }
}

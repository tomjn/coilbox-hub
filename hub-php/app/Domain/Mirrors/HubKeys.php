<?php

namespace App\Domain\Mirrors;

/** The hub's signing key from config, and the optional next key used during rotation. */
final class HubKeys
{
    private function __construct(
        private readonly string $secret,
        private readonly ?string $nextSecret,
    ) {}

    /** Returns null when the secret key is empty or does not decode to 64 bytes. */
    public static function fromConfig(): ?self
    {
        $secret = self::decodeSecret((string) config('hub.mirrors.secret_key'));
        if ($secret === null) {
            return null;
        }

        return new self($secret, self::decodeSecret((string) config('hub.mirrors.next_secret_key')));
    }

    private static function decodeSecret(string $encoded): ?string
    {
        $raw = B64::decode($encoded);

        return $raw !== null && strlen($raw) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ? $raw : null;
    }

    /** The raw 64 byte secret key. */
    public function secretKey(): string
    {
        return $this->secret;
    }

    /** The raw 32 byte public key. */
    public function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secret);
    }

    /**
     * The next public key and the current key's endorsement of it, both base64url.
     * Null when no valid next key is configured.
     *
     * @return array{public_key: string, sig: string}|null
     */
    public function nextKey(): ?array
    {
        if ($this->nextSecret === null) {
            return null;
        }
        $public = sodium_crypto_sign_publickey_from_secretkey($this->nextSecret);

        return [
            'public_key' => B64::encode($public),
            'sig' => B64::encode(Signing::sign(Signing::CTX_HUB_KEY, $public, $this->secret)),
        ];
    }
}

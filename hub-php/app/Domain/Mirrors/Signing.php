<?php

namespace App\Domain\Mirrors;

/** Detached Ed25519 signatures over a context string followed by the message. */
final class Signing
{
    public const CTX_TOKEN = "coilbox-mirror/token/v1\n";

    public const CTX_CATALOGUE = "coilbox-mirror/catalogue/v1\n";

    public const CTX_HUB_KEY = "coilbox-mirror/hub-key/v1\n";

    public const CTX_REPORT = "coilbox-mirror/report/v1\n";

    public const CTX_IDENTITY = "coilbox-mirror/identity/v1\n";

    /** Returns the raw 64 byte signature. */
    public static function sign(string $ctx, string $msg, string $secretKey): string
    {
        return sodium_crypto_sign_detached($ctx.$msg, $secretKey);
    }

    /** Returns false, and never throws, when the key or signature has the wrong length. */
    public static function verify(string $ctx, string $msg, string $sig, string $publicKey): bool
    {
        if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, $ctx.$msg, $publicKey);
    }
}

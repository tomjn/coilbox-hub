<?php

namespace App\Domain\Mirrors;

/** Opens, verifies and checks the shape of a mirror report (PROTOCOL.md section 7). */
final class Report
{
    /**
     * Returns the mirror id, the exact payload bytes and the raw signature, or an error code.
     * Nothing in the payload is trusted until verify() passes.
     *
     * @return array{mirror: string, payloadBytes: string, sig: string}|string
     */
    public static function open(string $body): array|string
    {
        $outer = json_decode($body);
        if (! $outer instanceof \stdClass || ! is_string($outer->payload ?? null) || ! is_string($outer->sig ?? null)) {
            return 'bad_request';
        }

        $payloadBytes = B64::decode($outer->payload);
        $sig = B64::decode($outer->sig);
        if ($payloadBytes === null || $sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return 'bad_request';
        }

        $payload = json_decode($payloadBytes);
        if (! $payload instanceof \stdClass || ! is_string($payload->mirror ?? null)
            || preg_match('/^[a-z0-9][a-z0-9-]{0,63}\z/', $payload->mirror) !== 1) {
            return 'bad_request';
        }

        return ['mirror' => $payload->mirror, 'payloadBytes' => $payloadBytes, 'sig' => $sig];
    }

    /** The public key is base64url. A key that does not decode never verifies. */
    public static function verify(string $payloadBytes, string $sig, string $publicKeyB64): bool
    {
        $key = B64::decode($publicKeyB64);

        return $key !== null && Signing::verify(Signing::CTX_REPORT, $payloadBytes, $sig, $key);
    }

    /** Checks the payload against the table in PROTOCOL.md section 7. */
    public static function validate(array $payload): bool
    {
        if (($payload['v'] ?? null) !== 1) {
            return false;
        }

        $event = $payload['event'] ?? null;
        $upload = $payload['upload'] ?? null;
        if ($event === 'upload') {
            if (! is_string($upload) || preg_match('/^[A-Za-z0-9-]{1,64}\z/', $upload) !== 1) {
                return false;
            }
        } elseif ($event === 'sync') {
            if ($upload !== null) {
                return false;
            }
        } else {
            return false;
        }

        if (! is_int($payload['sent_at'] ?? null)) {
            return false;
        }

        foreach (['catalogue_seq', 'catalogue_generated_at'] as $key) {
            if (! array_key_exists($key, $payload) || ($payload[$key] !== null && ! is_int($payload[$key]))) {
                return false;
            }
        }

        // features: what a mirror can do beyond its kinds, such as replace_track (#687).
        foreach (['kinds', 'features'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $list = $payload[$key];
            if (! is_array($list) || ! array_is_list($list)) {
                return false;
            }
            foreach ($list as $item) {
                if (! is_string($item)) {
                    return false;
                }
            }
        }

        $held = $payload['held'] ?? null;
        if (! is_array($held) || ! array_is_list($held)) {
            return false;
        }
        foreach ($held as $hash) {
            if (! is_string($hash) || preg_match('/^[0-9a-f]{64}\z/', $hash) !== 1) {
                return false;
            }
        }
        // Only once every entry is a string, since array_unique warns on arrays.
        if (count($held) !== count(array_unique($held))) {
            return false;
        }

        $unknown = $payload['unknown'] ?? null;
        if (! is_array($unknown) || ! array_is_list($unknown)) {
            return false;
        }
        foreach ($unknown as $entry) {
            if (! is_array($entry)) {
                return false;
            }
        }

        return true;
    }
}

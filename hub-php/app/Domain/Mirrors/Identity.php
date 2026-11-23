<?php

namespace App\Domain\Mirrors;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Asks a mirror to prove it holds a key. The hub sends a random challenge to
 * `{base}/identity.php`, and the mirror signs it together with its own base
 * URL, so an answer copied from another mirror fails.
 *
 * A signed in user chooses the base URL, so the hub only fetches it when
 * every address the host resolves to is public, and pins the request to the
 * address it checked.
 */
final class Identity
{
    public const MESSAGES = [
        'bad_url' => 'The address must start with https:// and have no user name, password, query string or # part.',
        'private_address' => 'The address points at a private or local network, so the hub will not contact it.',
        'unreachable' => 'The hub could not reach identity.php on the mirror. Check the address and that the mirror is set up.',
        'bad_answer' => 'The mirror\'s identity.php did not give a valid answer. Check that the mirror is up to date and set up.',
        'wrong_id' => 'The mirror says it has a different ID.',
        'wrong_url' => 'The mirror says it lives at a different address.',
        'wrong_key' => 'The mirror says it has a different public key.',
        'bad_signature' => 'The mirror could not prove it holds the secret key for its public key.',
    ];

    private readonly Closure $resolve;

    /** @param  (Closure(string): list<string>)|null  $resolve  Returns every IP address a host name resolves to. */
    public function __construct(?Closure $resolve = null)
    {
        $this->resolve = $resolve ?? self::lookup(...);
    }

    /** Null when the mirror proved it, otherwise a key of MESSAGES. */
    public function check(string $baseUrl, string $mirrorId, string $publicKey): ?string
    {
        $parts = self::parse($baseUrl);
        if ($parts === null) {
            return 'bad_url';
        }
        [$host, $port] = $parts;

        $address = $this->publicAddress($host);
        if ($address === null) {
            return 'private_address';
        }

        $challenge = random_bytes(32);
        // CURLOPT_RESOLVE wants IPv6 addresses in brackets.
        $pin = $host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address);
        try {
            $response = Http::withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => [$pin]]])
                ->accept('application/json')
                ->get($baseUrl.'/identity.php', ['challenge' => B64::encode($challenge)]);
        } catch (ConnectionException) {
            return 'unreachable';
        }
        if ($response->status() !== 200) {
            return 'unreachable';
        }

        $body = $response->json();
        if (! is_array($body)) {
            return 'bad_answer';
        }
        foreach (['mirror_id', 'base_url', 'public_key', 'sig'] as $field) {
            if (! is_string($body[$field] ?? null)) {
                return 'bad_answer';
            }
        }
        if ($body['mirror_id'] !== $mirrorId) {
            return 'wrong_id';
        }
        if ($body['base_url'] !== $baseUrl) {
            return 'wrong_url';
        }
        if ($body['public_key'] !== $publicKey) {
            return 'wrong_key';
        }

        $key = B64::decode($publicKey);
        $sig = B64::decode($body['sig']);
        if ($key === null || $sig === null || ! Signing::verify(Signing::CTX_IDENTITY, $challenge.$baseUrl, $sig, $key)) {
            return 'bad_signature';
        }

        return null;
    }

    /**
     * The host and port of an https base URL with no trailing slash, user,
     * password, query or fragment, or null when it is not one.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function parse(string $baseUrl): ?array
    {
        if (preg_match('/\s/', $baseUrl) === 1 || str_ends_with($baseUrl, '/')) {
            return null;
        }
        $parts = parse_url($baseUrl);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || str_contains($baseUrl, '?') || str_contains($baseUrl, '#')) {
            return null;
        }

        return [trim($parts['host'], '[]'), $parts['port'] ?? 443];
    }

    /** The first address the host resolves to, or null when it resolves to none or to any that is not public. */
    private function publicAddress(string $host): ?string
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);
        if ($addresses === []) {
            return null;
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return $addresses[0];
    }

    /** @return list<string> */
    private static function lookup(string $host): array
    {
        $v4 = gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values([...$v4, ...$v6]);
    }
}

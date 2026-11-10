<?php

namespace App\Supabase;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * One way of talking to Supabase, the PHP side of the four client kinds the
 * Next app has in `lib/supabase/`:
 *
 * - {@see Supabase::anon()}: the publishable key and no session. Row level
 *   security sees the `anon` role. Use it for anything cached and shared.
 * - {@see Supabase::forToken()}: the publishable key plus a signed in user's
 *   access token. Row level security sees that user.
 * - {@see Supabase::admin()}: the service role key. Bypasses row level
 *   security. Server only, and only where the Next app used `createAdminClient`.
 */
class Client
{
    public function __construct(
        private readonly string $url,
        private readonly string $apiKey,
        private readonly ?string $accessToken,
        private readonly int $timeout,
    ) {}

    public function from(string $table): Query
    {
        return new Query($this, $table);
    }

    /**
     * Call a Postgres function, as supabase-js's `.rpc()`. Returns whatever the
     * function returns, decoded: a list of rows, one row, a scalar or null.
     *
     * @param  array<string, mixed>  $args
     */
    public function rpc(string $function, array $args = []): mixed
    {
        $response = $this->send('POST', '/rest/v1/rpc/'.rawurlencode($function), [], $args === [] ? (object) [] : $args);

        return $response['json'];
    }

    /** The signed in user's access token, when this client has one. */
    public function accessToken(): ?string
    {
        return $this->accessToken;
    }

    /**
     * Download one object from a storage bucket. Returns null when the object
     * does not exist.
     */
    public function downloadObject(string $bucket, string $path): ?string
    {
        $response = $this->request()->get($this->url.'/storage/v1/object/'.rawurlencode($bucket).'/'.self::encodePath($path));
        if ($response->status() === 404 || $response->status() === 400) {
            return null;
        }
        if (! $response->successful()) {
            throw SupabaseException::fromResponse($response->status(), $response->json(), 'Storage download failed');
        }

        return $response->body();
    }

    /**
     * Upload one object. Throws a {@see SupabaseException} with status 409
     * when it exists and `$upsert` is false.
     */
    public function uploadObject(string $bucket, string $path, string $bytes, string $contentType, bool $upsert = false): void
    {
        $response = $this->request()
            ->withHeaders(['x-upsert' => $upsert ? 'true' : 'false', 'cache-control' => 'max-age=3600'])
            ->withBody($bytes, $contentType)
            ->post($this->url.'/storage/v1/object/'.rawurlencode($bucket).'/'.self::encodePath($path));
        if (! $response->successful()) {
            $body = $response->json();
            $status = (int) ($body['statusCode'] ?? $response->status());
            throw SupabaseException::fromResponse($status, is_array($body) ? $body : null, 'Storage upload failed');
        }
    }

    /** @param list<string> $paths */
    public function removeObjects(string $bucket, array $paths): void
    {
        if ($paths === []) {
            return;
        }
        $response = $this->request()->send('DELETE', $this->url.'/storage/v1/object/'.rawurlencode($bucket), [
            'json' => ['prefixes' => $paths],
        ]);
        if (! $response->successful()) {
            throw SupabaseException::fromResponse($response->status(), $response->json(), 'Storage remove failed');
        }
    }

    /**
     * @internal Used by {@see Query}.
     *
     * @param  array<string, string>  $headers
     * @return array{rows: list<array<string, mixed>>, count: int|null}
     */
    public function rest(string $method, string $table, string $query, mixed $body, array $headers): array
    {
        $path = '/rest/v1/'.rawurlencode($table).($query !== '' ? '?'.$query : '');
        $response = $this->send($method, $path, $headers, $body);
        $json = $response['json'];
        $rows = is_array($json) ? (array_is_list($json) ? $json : [$json]) : [];

        $count = null;
        $range = $response['headers']['content-range'] ?? null;
        if (is_string($range) && preg_match('#/(\d+)$#', $range, $m)) {
            $count = (int) $m[1];
        }

        return ['rows' => $rows, 'count' => $count];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{json: mixed, headers: array<string, string>}
     */
    private function send(string $method, string $path, array $headers, mixed $body): array
    {
        $request = $this->request()->withHeaders($headers);
        try {
            $response = $body === null
                ? $request->send($method, $this->url.$path)
                : $request->send($method, $this->url.$path, ['json' => $body]);
        } catch (ConnectionException $e) {
            throw new SupabaseException('Could not reach Supabase: '.$e->getMessage(), 0, 'CONNECTION');
        }

        $json = $response->body() === '' ? null : $response->json();
        if (! $response->successful()) {
            throw SupabaseException::fromResponse($response->status(), is_array($json) ? $json : null, 'Supabase request failed');
        }

        $flat = [];
        foreach ($response->headers() as $name => $values) {
            $flat[strtolower($name)] = is_array($values) ? implode(', ', $values) : (string) $values;
        }

        return ['json' => $json, 'headers' => $flat];
    }

    private function request(): PendingRequest
    {
        $headers = ['apikey' => $this->apiKey];
        // A user's token wins. Otherwise a key that is itself a JWT (the older
        // service role key) has to go in Authorization too. The newer
        // `sb_publishable_` and `sb_secret_` keys must not.
        $bearer = $this->accessToken ?? (str_starts_with($this->apiKey, 'eyJ') ? $this->apiKey : null);
        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        return Http::withHeaders($headers)->acceptJson()->timeout($this->timeout)->connectTimeout(5);
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}

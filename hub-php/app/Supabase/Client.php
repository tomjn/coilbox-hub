<?php

namespace App\Supabase;

use App\Auth\GoTrue;
use App\Supabase\Local\DataMove;
use App\Supabase\Local\GameFunctions;
use App\Supabase\Local\GameStore;
use App\Supabase\Local\ItemFunctions;
use App\Supabase\Local\ItemStore;
use App\Supabase\Local\MapFunctions;
use App\Supabase\Local\MapStore;
use App\Supabase\Local\MirrorFunctions;
use App\Supabase\Local\MirrorStore;
use App\Supabase\Local\PictureFunctions;
use App\Supabase\Local\PictureStore;
use App\Supabase\Local\Rest;
use App\Supabase\Local\StagedObjects;
use App\Supabase\Local\Tables;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
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
 *
 * Once the game catalogue has moved (#597), requests for its tables and
 * functions are answered from MariaDB by {@see Rest} and {@see GameFunctions},
 * which apply the same row level security for the same caller. Pictures
 * (#598) move the same way on their own switch: their tables and functions go
 * to {@see Rest} and {@see PictureFunctions}, and the `staged-pictures` bucket
 * to {@see StagedObjects} on the droplet's disk. Maps (#599) follow on a third
 * switch, with {@see MapFunctions} and the map views in {@see MapViews}, and
 * the hub mirrors (#600) on a fourth, with {@see MirrorFunctions}, and
 * published items, reports, profiles and capabilities (#601) on a fifth, with
 * {@see ItemFunctions}. After that only sign in uses Supabase.
 * `remote()` gives a client that always asks Supabase, for the copies and for
 * keeping Supabase's `map` rows in step.
 */
class Client
{
    public function __construct(
        private readonly string $url,
        private readonly string $apiKey,
        private readonly ?string $accessToken,
        private readonly int $timeout,
        private readonly ?string $userId = null,
        private readonly bool $remoteOnly = false,
        private readonly ?string $sessionAal = null,
    ) {}

    /** This client, asking Supabase even for tables that have moved. */
    public function remote(): self
    {
        return new self($this->url, $this->apiKey, $this->accessToken, $this->timeout, $this->userId, true, $this->sessionAal);
    }

    /** This client with another request timeout in seconds, 0 for none. */
    public function withTimeout(int $seconds): self
    {
        return new self($this->url, $this->apiKey, $this->accessToken, $seconds, $this->userId, $this->remoteOnly, $this->sessionAal);
    }

    /** `service_role`, `authenticated` or `anon`, as row level security sees the caller. */
    public function role(): string
    {
        if ($this->apiKey !== '' && $this->apiKey === (string) config('hub.supabase.service_role_key')) {
            return 'service_role';
        }

        return $this->accessToken !== null || $this->sessionAal !== null ? 'authenticated' : 'anon';
    }

    /** Whether this client is a hub session, signed in without a Supabase token (#602). */
    public function hubSession(): bool
    {
        return $this->sessionAal !== null;
    }

    /** The caller's authenticator level, `aal1` or `aal2`: the hub session's, or the access token's claim. */
    public function aal(): string
    {
        if ($this->sessionAal !== null) {
            return $this->sessionAal;
        }

        return $this->accessToken !== null && (GoTrue::claims($this->accessToken)['aal'] ?? null) === 'aal2' ? 'aal2' : 'aal1';
    }

    /** The signed in user's id when the creator knew it. */
    public function knownUserId(): ?string
    {
        return $this->userId;
    }

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
        if (! $this->remoteOnly && GameFunctions::handles($function)) {
            $target = GameFunctions::writes($function) ? GameStore::writeTarget() : GameStore::state();
            if ($target === GameStore::MARIADB) {
                return GameFunctions::call($this, $function, $args);
            }
        }
        if (! $this->remoteOnly && PictureFunctions::handles($function)) {
            $target = PictureFunctions::writes($function) ? PictureStore::writeTarget() : PictureStore::state();
            if ($target === PictureStore::MARIADB) {
                return PictureFunctions::call($this, $function, $args);
            }
        }
        if (! $this->remoteOnly && MapFunctions::handles($function)) {
            $target = MapFunctions::writes($function) ? MapStore::writeTarget() : MapStore::state();
            if ($target === MapStore::MARIADB) {
                return MapFunctions::call($this, $function, $args);
            }
        }
        if (! $this->remoteOnly && MirrorFunctions::handles($function)) {
            $target = MirrorFunctions::writes($function) ? MirrorStore::writeTarget() : MirrorStore::state();
            if ($target === MirrorStore::MARIADB) {
                return MirrorFunctions::call($this, $function, $args);
            }
        }
        if (! $this->remoteOnly && ItemFunctions::handles($function)) {
            $target = ItemFunctions::writes($function) ? ItemStore::writeTarget() : ItemStore::state();
            if ($target === ItemStore::MARIADB) {
                return ItemFunctions::call($this, $function, $args);
            }
        }
        $response = $this->send('POST', '/rest/v1/rpc/'.rawurlencode($function), [], $args === [] ? (object) [] : $args);

        return $response['json'];
    }

    /**
     * The same function called once for each set of arguments, the requests
     * sent together. Answers in the order asked. Always asks Supabase.
     *
     * @param  list<array<string, mixed>>  $argsList
     * @return list<mixed>
     */
    public function rpcEach(string $function, array $argsList): array
    {
        if ($argsList === []) {
            return [];
        }
        $url = $this->url.'/rest/v1/rpc/'.rawurlencode($function);
        try {
            $responses = Http::pool(function (Pool $pool) use ($argsList, $url) {
                foreach (array_values($argsList) as $i => $args) {
                    $this->configure($pool->as((string) $i))->post($url, $args === [] ? (object) [] : $args);
                }
            });
        } catch (ConnectionException $e) {
            throw new SupabaseException('Could not reach Supabase: '.$e->getMessage(), 0, 'CONNECTION');
        }
        $answers = [];
        foreach (array_keys(array_values($argsList)) as $i) {
            $response = $responses[(string) $i] ?? null;
            if (! $response instanceof Response) {
                throw new SupabaseException('Could not reach Supabase', 0, 'CONNECTION');
            }
            $json = $response->body() === '' ? null : $response->json();
            if (! $response->successful()) {
                throw SupabaseException::fromResponse($response->status(), is_array($json) ? $json : null, 'Supabase request failed');
            }
            $answers[] = $json;
        }

        return $answers;
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
        if ($this->localBucket($bucket, false)) {
            return StagedObjects::download($path);
        }
        $response = $this->request()->get($this->url.'/storage/v1/object/'.rawurlencode($bucket).'/'.self::encodePath($path));
        if ($response->status() === 404 || $response->status() === 400) {
            return null;
        }
        if (! $response->successful()) {
            throw SupabaseException::fromResponse($response->status(), is_array($response->json()) ? $response->json() : null, 'Storage download failed');
        }

        return $response->body();
    }

    /**
     * Upload one object. Throws a {@see SupabaseException} with status 409
     * when it exists and `$upsert` is false.
     */
    public function uploadObject(string $bucket, string $path, string $bytes, string $contentType, bool $upsert = false): void
    {
        if ($this->localBucket($bucket, true)) {
            StagedObjects::upload($path, $bytes, $contentType, $upsert);

            return;
        }
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
        if ($this->localBucket($bucket, true)) {
            StagedObjects::remove($paths);

            return;
        }
        $response = $this->request()->send('DELETE', $this->url.'/storage/v1/object/'.rawurlencode($bucket), [
            'json' => ['prefixes' => $paths],
        ]);
        if (! $response->successful()) {
            throw SupabaseException::fromResponse($response->status(), is_array($response->json()) ? $response->json() : null, 'Storage remove failed');
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
        if (! $this->remoteOnly && Tables::handles($table)) {
            $reads = $method === 'GET' || $method === 'HEAD';
            $store = Tables::store($table);
            if (($reads ? $store::state() : $store::writeTarget()) === DataMove::MARIADB) {
                return Rest::handle($this, $method, $table, $query, $body, $headers);
            }
        }
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
     * Rows read straight from Supabase with JSON objects kept as objects, so
     * an empty `{}` stays an object rather than becoming an empty list. For
     * copying rows out.
     *
     * @return list<array<string, mixed>>
     */
    public function rowsKeepingObjects(string $table, string $query): array
    {
        $response = $this->send('GET', '/rest/v1/'.rawurlencode($table).'?'.$query, [], null);
        $rows = json_decode($response['body'], false, 512, JSON_THROW_ON_ERROR);

        return array_map(fn ($row) => get_object_vars($row), is_array($rows) ? $rows : []);
    }

    /**
     * One page of a bucket folder from Supabase Storage: files with their
     * metadata, and folders with a null id. Always asks Supabase. For copying
     * the bucket out.
     *
     * @return list<array<string, mixed>>
     */
    public function listObjects(string $bucket, string $prefix, int $limit, int $offset): array
    {
        try {
            $response = $this->request()->post($this->url.'/storage/v1/object/list/'.rawurlencode($bucket), [
                'prefix' => $prefix, 'limit' => $limit, 'offset' => $offset, 'sortBy' => ['column' => 'name', 'order' => 'asc'],
            ]);
        } catch (ConnectionException $e) {
            throw new SupabaseException('Could not reach Supabase: '.$e->getMessage(), 0, 'CONNECTION');
        }
        if (! $response->successful()) {
            throw SupabaseException::fromResponse($response->status(), is_array($response->json()) ? $response->json() : null, 'Storage list failed');
        }
        $items = $response->json();

        return is_array($items) ? array_values($items) : [];
    }

    /** Whether this bucket request is answered from the droplet's disk. */
    private function localBucket(string $bucket, bool $writes): bool
    {
        if ($this->remoteOnly || $bucket !== StagedObjects::BUCKET) {
            return false;
        }

        return ($writes ? PictureStore::writeTarget() : PictureStore::state()) === PictureStore::MARIADB;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{json: mixed, headers: array<string, string>, body: string}
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

        return ['json' => $json, 'headers' => $flat, 'body' => $response->body()];
    }

    private function request(): PendingRequest
    {
        return $this->configure(Http::createPendingRequest());
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        $headers = ['apikey' => $this->apiKey];
        // A user's token wins. Otherwise a key that is itself a JWT (the older
        // service role key) has to go in Authorization too. The newer
        // `sb_publishable_` and `sb_secret_` keys must not.
        $bearer = $this->accessToken ?? (str_starts_with($this->apiKey, 'eyJ') ? $this->apiKey : null);
        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        return $request->withHeaders($headers)->acceptJson()->timeout($this->timeout)->connectTimeout(5);
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}

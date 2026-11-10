<?php

namespace Tests\Feature\Moderation;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A faked Supabase and a faked session for the moderation pages. Nothing here
 * reaches the network, and every write a test makes lands in `$sent`.
 */
abstract class ModerationTestCase extends TestCase
{
    protected const URL = 'https://sb.test';

    protected const MODERATOR = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

    /** @var list<Request> */
    protected array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => self::URL,
            'hub.supabase.publishable_key' => 'sb_publishable_test',
            'hub.supabase.service_role_key' => 'service-key',
        ]);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    /** A signed in session, the way HubSession stores one. */
    protected function signedIn(string $aal = 'aal1'): static
    {
        return $this->withSession(['supabase' => [
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_at' => time() + 3600,
            'user_id' => self::MODERATOR,
            'aal' => $aal,
        ]]);
    }

    /**
     * Supabase. `$routes` maps `METHOD fragment` (or just a fragment) to a
     * response, checked in order before the defaults. `is_moderator` answers
     * `$moderator` and `capability_access` answers `$access` unless a route
     * says otherwise. Anything unmatched is a 500, so a request nobody
     * expected fails loudly.
     *
     * @param  array<string, mixed>  $routes
     */
    protected function supabase(array $routes = [], bool $moderator = true, string $access = 'none'): void
    {
        $routes += [
            'POST /rpc/is_moderator' => self::reply($moderator),
            'POST /rpc/capability_access' => self::reply($access),
        ];
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($routes) {
            $this->sent[] = $request;
            $url = urldecode($request->url());
            foreach ($routes as $fragment => $response) {
                [$method, $needle] = str_contains($fragment, ' ') ? explode(' ', $fragment, 2) : [null, $fragment];
                if (($method === null || $method === $request->method()) && str_contains($url, $needle)) {
                    return is_callable($response) ? $response($request) : $response;
                }
            }

            return Http::response(['message' => 'unexpected '.$request->method().' '.$url], 500);
        });
    }

    protected static function reply(mixed $value, int $status = 200, array $headers = []): mixed
    {
        return Http::response(json_encode($value), $status, ['Content-Type' => 'application/json'] + $headers);
    }

    /** A PostgREST error with a code. */
    protected static function error(string $code = 'XX000', int $status = 400): mixed
    {
        return self::reply(['code' => $code, 'message' => 'boom'], $status);
    }

    /** @return list<Request> */
    protected function sentWrites(): array
    {
        return array_values(array_filter($this->sent, fn (Request $r) => in_array($r->method(), ['PATCH', 'DELETE'], true)
            || ($r->method() === 'POST' && ! str_contains($r->url(), '/rpc/is_moderator') && ! str_contains($r->url(), '/rpc/capability_access'))));
    }
}

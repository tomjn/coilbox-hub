<?php

namespace App\Http\Middleware;

use App\Domain\Promotion\JobToken;
use App\Support\Api;
use App\Support\Bearer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Lets a request through to `/api/v1/promotion` only with the job's token. */
final class PromotionJobToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! JobToken::accepts(Bearer::token($request))) {
            return Api::error('Send the promotion job token as "Authorization: Bearer <token>".', 401);
        }

        return $next($request);
    }
}

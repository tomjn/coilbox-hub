<?php

namespace App\Http\Controllers\Replays;

use App\Domain\Replays\Tracks;
use App\Domain\Replays\TrackUploads;
use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\Bearer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** A replay's tracks: anyone can list them, and signed-in Coilbox users can send one. */
class ReplayTrackController extends Controller
{
    public function index(string $gameId): JsonResponse
    {
        if (DB::table('replay')->where('game_id', $gameId)->where('state', 'listed')->doesntExist()) {
            return Api::json(['error' => 'The hub has no listed replay with this game id.', 'reason' => 'not_listed'], 404, 'no-store');
        }

        return Api::json(['tracks' => Tracks::listed($gameId)]);
    }

    public function store(Request $request, string $gameId): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $result = TrackUploads::start($auth, $gameId, $request->getContent());

        return Api::json($result['body'], $result['status'], 'no-store');
    }

    public function preflight(): Response
    {
        return Api::preflight();
    }
}

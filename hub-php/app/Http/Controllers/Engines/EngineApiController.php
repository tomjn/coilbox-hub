<?php

namespace App\Http\Controllers\Engines;

use App\Domain\Engines\EngineFilename;
use App\Domain\Engines\Engines;
use App\Http\Controllers\Controller;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/** The public list of engines Coilbox offers, with the mirrors that hold each one. */
class EngineApiController extends Controller
{
    public function index(Request $request): BaseResponse
    {
        $platform = $request->query('platform');
        if ($platform !== null && (! is_string($platform) || ! in_array($platform, EngineFilename::PLATFORMS, true))) {
            return Api::error('Unknown platform. Use one of: '.implode(', ', EngineFilename::PLATFORMS).'.', 400);
        }

        // Coilbox cannot download an engine that no healthy mirror holds.
        $engines = array_values(array_filter(Engines::listed($platform), fn (array $e) => $e['sources'] !== []));
        $engines = array_map(function (array $e) {
            unset($e['id']);

            return $e;
        }, $engines);

        return Api::json(['engines' => $engines], 200, 'no-store');
    }

    public function preflight(): Response
    {
        return Api::preflight();
    }
}

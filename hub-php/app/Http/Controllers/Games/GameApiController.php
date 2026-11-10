<?php

namespace App\Http\Controllers\Games;

use App\Domain\Assets\ImageHeader;
use App\Domain\Assets\Staging;
use App\Domain\Games\Editor;
use App\Domain\Games\GameBranding;
use App\Domain\Games\GameFacts;
use App\Domain\Games\GameList;
use App\Domain\Games\Query;
use App\Domain\Games\Submit;
use App\Http\Controllers\Controller;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\Api;
use App\Support\Bearer;
use App\Support\HubCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The desktop app's games API: `app/api/v1/games/route.ts`,
 * `app/api/v1/games/facts/route.ts` and `app/api/v1/games/branding/route.ts`
 * in the Next app.
 */
class GameApiController extends Controller
{
    public function preflight(): Response
    {
        return Api::preflight();
    }

    /**
     * Every game the hub holds, through the same read the listing page makes,
     * so the two cannot disagree. A failed read is a 503, not an empty list:
     * an empty list is a claim that the hub holds no games.
     */
    public function index(): JsonResponse
    {
        $listing = Query::fetchGames(Supabase::anon());
        if ($listing['error'] !== null) {
            Log::error('GET /api/v1/games: the catalog could not be read', ['error' => $listing['error']]);

            return Api::error('The catalog could not be read just now.', 503);
        }

        return Api::json(GameList::body($listing['games']));
    }

    /**
     * Where a game's facts arrive. The hub never opens an archive. Coilbox
     * reads it and posts the result here, one game at one release per request.
     * A token is needed because this writes and `submitted_by` names an account.
     */
    public function facts(Request $request): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        // The declared length first, so a body far past the cap is refused
        // before it is read. What actually arrived is measured below.
        $declared = $request->header('Content-Length');
        if (is_numeric($declared) && (float) $declared > GameFacts::MAX_BYTES) {
            return Api::error('A request body may be at most '.GameFacts::MAX_BYTES.' bytes.', 413);
        }
        $raw = $request->getContent();
        if (strlen($raw) > GameFacts::MAX_BYTES) {
            return Api::error('A request body may be at most '.GameFacts::MAX_BYTES.' bytes.', 413);
        }

        $body = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return Api::error('The request body must be JSON.', 400);
        }

        $parsed = GameFacts::parse($body);
        if (! $parsed['ok']) {
            return Api::error($parsed['error'], $parsed['status']);
        }

        // Download sources travel apart from the facts and never enter
        // `submit_game_facts`. They are offered and held, not written.
        $downloads = $parsed['submission']['downloads'];
        $facts = $parsed['submission'];
        unset($facts['downloads']);

        $submission = Submit::buildGameSubmission($facts);

        try {
            $admin = Supabase::admin();
        } catch (SupabaseException) {
            return Api::error('This deployment has not configured Supabase. Set SUPABASE_SERVICE_ROLE_KEY.', 503);
        }

        $written = Submit::submitGameFacts($admin, $submission, $auth->userId);
        if (! $written['ok']) {
            return Api::error('The game catalog could not be written just now.', 503);
        }

        // After the facts, because the game may not have existed until they
        // landed. A queue the hub could not write never costs the submission.
        if ($downloads !== null && $downloads !== []) {
            $held = Submit::offerGameDownloadSources($admin, $submission['shortname'], $downloads, $auth->userId);
            if ($held === null) {
                Log::error('POST /api/v1/games/facts: '.$submission['shortname'].' offered nowhere to download');
            }
        }

        HubCache::invalidate([HubCache::GAMES]);

        return Api::json([
            'format' => GameFacts::FORMAT,
            'version' => GameFacts::VERSION,
            'results' => $written['results'],
        ], 200, null);
    }

    /**
     * Where a game's own art arrives, one logo, banner or card per request,
     * from the game's owner or a moderator. The gate is the moderation: an
     * owner choosing their own game's face needs no review queue.
     */
    public function branding(Request $request): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $type = strtolower((string) $request->header('Content-Type'));
        if (! str_contains($type, 'multipart/form-data') && ! str_contains($type, 'application/x-www-form-urlencoded')) {
            return Api::error('The request body must be multipart/form-data with "shortname", "kind" and "file" parts.', 415);
        }

        $parsed = GameBranding::parseFields($request->input('shortname'), $request->input('kind'));
        if (! $parsed['ok']) {
            return Api::error($parsed['error'], 400);
        }

        $file = $request->file('file');
        if ($file === null || is_array($file) || ! $file->isValid()) {
            return Api::error('The "file" part is required and must carry the encoded bytes.', 400);
        }
        if ($file->getSize() > GameBranding::MAX_BYTES) {
            return Api::error('A picture may be at most '.GameBranding::MAX_BYTES.' bytes.', 413);
        }
        if ($file->getSize() === 0) {
            return Api::error('The "file" part is empty.', 400);
        }
        $bytes = (string) file_get_contents($file->getRealPath());

        // What the bytes are, read from the whole body rather than anything
        // declared. This goes straight onto a public game row, so the header
        // parse is all the checking it gets.
        $image = ImageHeader::read($bytes);
        if ($image === null) {
            return Api::error('The "file" part must be a PNG or WebP the hub can measure.', 400);
        }

        $shortname = $parsed['shortname'];
        $kind = $parsed['kind'];
        $path = 'games/'.$shortname.'/'.$kind.'.'.($image['mime'] === 'image/png' ? 'png' : 'webp');

        try {
            $admin = Supabase::admin();
        } catch (SupabaseException) {
            return Api::error('This deployment has not configured Supabase. Set SUPABASE_SERVICE_ROLE_KEY.', 503);
        }

        // Asked through the visitor's own client, so row level security
        // answers. The secret key is spent only after this finds the game.
        $owned = Editor::editableGame($auth->client, $auth->userId, $shortname);
        if ($owned === null) {
            return Api::error("Only the game's owner or a moderator may send its art.", 403);
        }

        // A repeat of the bytes already on the row writes nothing.
        $hash = hash('sha256', $bytes);
        // The Next route named the banner columns for every kind but the logo,
        // so a card upload overwrote the banner. Each kind writes its own here.
        $pathColumn = $kind.'_path';
        $hashColumn = $kind.'_hash';
        $stagedColumn = $kind.'_staged_tier';
        try {
            $held = $admin->from('game')->select($pathColumn.','.$hashColumn.','.$stagedColumn)->eq('id', $owned['id'])->maybeSingle();
        } catch (SupabaseException) {
            $held = null;
        }
        if ($held !== null && ($held[$hashColumn] ?? null) === $hash && ($held[$pathColumn] ?? null) !== null) {
            return Api::json(['format' => GameBranding::FORMAT, 'version' => GameBranding::VERSION, 'outcome' => 'unchanged', 'kind' => $kind], 200, 'no-store');
        }

        try {
            Staging::putGameImage($admin, $path, $bytes, $image['mime']);
        } catch (SupabaseException) {
            return Api::error('The asset store would not accept that upload just now.', 502);
        }

        // The row records that the staged copy is in the bucket, so promotion
        // reads it from there.
        try {
            $admin->from('game')->update([$pathColumn => $path, $hashColumn => $hash, $stagedColumn => 'bucket'])->eq('id', $owned['id'])->execute();
        } catch (SupabaseException) {
            return Api::error('The picture was uploaded but the game row could not be written.', 503);
        }

        HubCache::invalidate([HubCache::GAMES]);

        return Api::json(['format' => GameBranding::FORMAT, 'version' => GameBranding::VERSION, 'outcome' => 'stored', 'kind' => $kind], 200, 'no-store');
    }
}

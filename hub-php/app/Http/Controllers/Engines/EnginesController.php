<?php

namespace App\Http\Controllers\Engines;

use App\Auth\HubSession;
use App\Domain\Engines\Engines;
use App\Domain\Mirrors\Removal;
use App\Domain\Moderation\Access;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/engines`, unlisted pages for unofficial engine builds. They are not in the
 * nav or the sitemap, and ask search engines to stay away.
 */
class EnginesController extends Controller
{
    private const NOTE_MESSAGES = [
        'saved' => 'Note saved.',
        'not_allowed' => 'Only the uploader or a moderator can change the note.',
        'not_found' => 'That engine no longer exists.',
        'bad_note' => 'The note must be 1 to '.Engines::NOTE_MAX.' characters.',
    ];

    public function index(): View
    {
        $byPlatform = [];
        foreach (Engines::listed() as $engine) {
            $byPlatform[$engine['platform']][] = $engine;
        }
        ksort($byPlatform);

        return view('engines.index', ['byPlatform' => $byPlatform, 'state' => session('enginesState') ?? session('archivesState')]);
    }

    public function show(string $version, string $platform): View
    {
        $engine = Engines::find($version, $platform) ?? abort(404);
        $client = HubSession::client();
        $user = HubSession::userId();

        return view('engines.show', [
            'engine' => $engine,
            'canEdit' => $user !== null && $engine['uploader'] === $user,
            'moderator' => $client !== null && Access::isModerator($client),
            'state' => session('enginesState') ?? session('archivesState'),
            // The moderator remove form reads these from the archive row.
            'a' => ['id' => $engine['id'], 'filename' => $engine['filename']],
        ]);
    }

    public function note(Request $request, string $version, string $platform): RedirectResponse
    {
        $engine = Engines::find($version, $platform) ?? abort(404);
        $client = HubSession::client();
        $user = HubSession::userId();
        if ($client === null || $user === null) {
            return redirect('/publish');
        }

        $outcome = Engines::setNote($engine['id'], $user, Access::isModerator($client), (string) $request->input('note', ''));

        return redirect(Engines::pagePath($version, $platform))->with('enginesState', [
            'ok' => $outcome === 'saved',
            'message' => self::NOTE_MESSAGES[$outcome],
        ]);
    }

    public function withdraw(string $version, string $platform): RedirectResponse
    {
        $engine = Engines::find($version, $platform) ?? abort(404);
        $client = HubSession::client();
        if ($client === null || HubSession::userId() === null) {
            return redirect('/publish');
        }

        $state = Removal::remove($client, $engine['id'], 'withdrawn', null);

        return redirect($state['ok'] ? '/engines' : Engines::pagePath($version, $platform))->with('enginesState', $state);
    }
}

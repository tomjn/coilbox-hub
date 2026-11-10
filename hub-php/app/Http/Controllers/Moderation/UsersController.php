<?php

namespace App\Http\Controllers\Moderation;

use App\Domain\Gallery\GalleryQuery;
use App\Domain\Moderation\Access;
use App\Domain\Moderation\Accounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/moderation/users`, the account directory, from `app/moderation/users`
 * (issue #385). A full page may have a next one and a short page cannot, so
 * no count is asked for.
 */
class UsersController extends ModerationController
{
    public function index(Request $request): View|RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return Access::refuse('/moderation/users');
        }
        $q = $request->query('q');
        $term = is_string($q) ? trim($q) : '';
        $page = $request->query('page');
        $current = GalleryQuery::parsePage(is_string($page) ? $page : null, Accounts::PAGE_SIZE) ?? 1;
        // The database function takes the offset as a 32 bit integer.
        $current = min($current, intdiv(2147483647, Accounts::PAGE_SIZE));
        $result = Accounts::fetch($supabase, $term === '' ? null : $term, ($current - 1) * Accounts::PAGE_SIZE);

        $href = function (int $target) use ($term): string {
            $params = [];
            if ($term !== '') {
                $params['q'] = $term;
            }
            if ($target > 1) {
                $params['page'] = (string) $target;
            }

            return $params === [] ? '/moderation/users' : '/moderation/users?'.http_build_query($params);
        };

        return view('moderation.users', $result + [
            'term' => $term,
            'previous' => $current > 1 ? $href($current - 1) : null,
            'next' => count($result['accounts']) === Accounts::PAGE_SIZE ? $href($current + 1) : null,
        ]);
    }
}

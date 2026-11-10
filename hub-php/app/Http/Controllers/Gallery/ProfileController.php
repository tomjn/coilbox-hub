<?php

namespace App\Http\Controllers\Gallery;

use App\Domain\Profile\Contributions;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/** `/profile/<id>`, from `app/profile/[id]/page.tsx`. */
class ProfileController extends Controller
{
    public function show(string $id): Response
    {
        $accountId = Contributions::parseAccountId($id);
        $contributions = $accountId !== null ? Contributions::cached($accountId) : null;
        if ($contributions === null) {
            return response()->view('gallery.not-found', ['title' => 'Page not found'], 404);
        }

        return response()->view('gallery.profile', [
            'title' => $contributions['name'],
            'accountId' => $accountId,
            'contributions' => $contributions,
        ]);
    }
}

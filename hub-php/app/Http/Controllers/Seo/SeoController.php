<?php

namespace App\Http\Controllers\Seo;

use App\Domain\Seo\Robots;
use App\Domain\Seo\Sitemap;
use App\Http\Controllers\Controller;
use App\Support\Site;
use Illuminate\Http\Response;

/** `/robots.txt` and `/sitemap.xml`, from `app/robots.ts` and `app/sitemap.ts`. */
class SeoController extends Controller
{
    public function robots(): Response
    {
        return new Response(Robots::text(Site::url()), 200, [
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'public, max-age=0, must-revalidate',
        ]);
    }

    public function sitemap(): Response
    {
        return new Response(Sitemap::build(Site::url()), 200, [
            'Content-Type' => 'application/xml',
            'Cache-Control' => 'public, max-age=0, must-revalidate',
        ]);
    }
}

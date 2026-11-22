<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Markdown written on the hub, as HTML. GitHub's flavour, so tables, task
 * lists and bare links work. Raw HTML in the text shows as text, and links to
 * `javascript:` and other unsafe schemes are dropped, so stored text can never
 * run script on the page.
 */
final class Markdown
{
    public static function html(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        return Str::markdown($text, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }
}

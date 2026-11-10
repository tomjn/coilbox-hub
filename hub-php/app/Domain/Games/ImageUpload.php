<?php

namespace App\Domain\Games;

use Illuminate\Http\UploadedFile;

/**
 * What the logo, banner and card forms on a game's edit page say,
 * `lib/games/imageUpload.ts`. Every refusal says why, because a form that
 * silently did nothing is how an owner lost track of which pictures had saved.
 *
 * Shrinking or converting a picture happens in the browser first
 * (`resources/js/games/imageResize.ts`). The server keeps refusing anything
 * that is not a PNG or WebP under the cap, because a request can skip the
 * browser.
 */
final class ImageUpload
{
    public const UPLOAD_MESSAGES = [
        'noFile' => 'Choose a PNG, WebP or JPEG file to upload.',
        'wrongType' => 'This file is not a PNG or WebP picture. Save it as PNG or WebP and try again.',
        'signedOut' => 'You are signed out. Sign in, then upload the picture again.',
        'notAllowed' => 'You can no longer change this game. Only its owner or a moderator can upload its pictures.',
        'notSaved' => 'The picture could not be saved. Try again in a few minutes.',
        'notSent' => 'The upload did not reach the hub. Reload the page and try again.',
        'noKind' => 'The upload did not say which picture it is. Reload the page and try again.',
    ];

    public const REMOVE_MESSAGES = [
        'signedOut' => 'You are signed out. Sign in, then remove the picture again.',
        'notAllowed' => 'You can no longer change this game. Only its owner or a moderator can remove its pictures.',
        'notSaved' => 'The picture could not be removed. Try again in a few minutes.',
        'notSent' => 'The removal did not reach the hub. Reload the page and try again.',
    ];

    /** What each picture is called in the sentence confirming it saved or went away. */
    public const LABELS = ['logo' => 'Logo', 'banner' => 'Banner', 'card' => 'Card art'];

    /** The same picture, mid sentence, as the buttons and confirmations name it. */
    public const LOWER_LABELS = ['logo' => 'logo', 'banner' => 'banner', 'card' => 'card art'];

    /**
     * The sentences the browser writes when it shrank or converted a picture
     * before sending it, `describeConversion` in `imageResize.ts`. The browser
     * sends its sentence with the upload so the answer can say both. Anything
     * that is not one of these shapes is dropped, so a hand written post cannot
     * put its own words on the hub's page.
     */
    private const NOTE = '/\A(Shrunk from \d+ KB to \d+ KB\.|Converted from (JPEG|PNG|WebP|the original file) \(\d+ KB\) to (WebP|PNG) \(\d+ KB\)\.)\z/';

    public static function kilobytes(int $bytes): string
    {
        return ((int) ceil($bytes / 1024)).' KB';
    }

    /**
     * The refusal for a missing, empty or oversized file, or null when the
     * file may be stored. What the bytes are is read afterwards.
     *
     * @return array{ok: false, message: string}|null
     */
    public static function refuseImageFile(mixed $file): ?array
    {
        if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() === 0) {
            return ['ok' => false, 'message' => self::UPLOAD_MESSAGES['noFile']];
        }
        if ($file->getSize() > GameBranding::MAX_BYTES) {
            return ['ok' => false, 'message' => 'This file is '.self::kilobytes($file->getSize()).'. The largest picture you can upload is '
                .self::kilobytes(GameBranding::MAX_BYTES).'. Make it smaller and try again.'];
        }

        return null;
    }

    /** The browser's conversion sentence when it is one the browser writes, otherwise an empty string. */
    public static function note(mixed $value): string
    {
        return is_string($value) && preg_match(self::NOTE, $value) === 1 ? $value : '';
    }
}

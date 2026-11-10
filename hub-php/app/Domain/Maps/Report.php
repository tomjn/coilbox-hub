<?php

namespace App\Domain\Maps;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * Reporting a broken map, `lib/maps/report.ts` and `reportMap` in
 * `app/moderation/actions.ts`. The kinds match `report_kind_check` in the
 * database, so a kind it does not know is never sent.
 */
final class Report
{
    public const KINDS = [
        'crashes_engine' => 'Crashes the engine',
        'files_missing' => 'Files are missing or corrupt',
        'download_wrong' => 'The download is wrong or dead',
        'wrong_map' => 'It is not the map it says it is',
        'other' => 'Something else',
    ];

    public const MESSAGES = [
        'sent' => 'Reported. Thank you.',
        'noKind' => 'Choose what is wrong with the map.',
        'noReason' => 'Say what is wrong, in a sentence or two.',
        'notSent' => 'The form did not reach the hub. Reload the page and try again.',
        'notSaved' => 'That could not be sent. Try again in a few minutes.',
    ];

    /**
     * Check the form and file the report. No account is needed, and a signed in
     * reporter is named. The reason is cut to the column's thousand characters.
     *
     * @return array{ok: bool, message: string}
     */
    public static function file(Client $supabase, ?string $reporterId, mixed $mapId, mixed $kind, mixed $reason): array
    {
        if (! is_string($mapId) || $mapId === '') {
            return ['ok' => false, 'message' => self::MESSAGES['notSent']];
        }
        if (! is_string($kind) || ! array_key_exists($kind, self::KINDS)) {
            return ['ok' => false, 'message' => self::MESSAGES['noKind']];
        }
        $reason = Js::trim(is_string($reason) ? $reason : '');
        if ($reason === '') {
            return ['ok' => false, 'message' => self::MESSAGES['noReason']];
        }

        try {
            $supabase->from('report')->insert([
                'map_id' => $mapId,
                'kind' => $kind,
                'reason' => Js::slice($reason, 1000),
                'reporter_id' => $reporterId,
            ])->execute();
        } catch (SupabaseException) {
            return ['ok' => false, 'message' => self::MESSAGES['notSaved']];
        }

        return ['ok' => true, 'message' => self::MESSAGES['sent']];
    }
}

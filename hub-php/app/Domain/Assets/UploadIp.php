<?php

namespace App\Domain\Assets;

use App\Supabase\Client;
use App\Supabase\SupabaseException;
use Illuminate\Http\Request;

/**
 * Where an upload came from, `lib/assets/uploadIp.ts` in the Next app. Kept
 * while a picture is pending or rejected and purged by a trigger on approval.
 */
final class UploadIp
{
    /**
     * The address the request came from, or null. `x-real-ip` first, because
     * the proxy sets it to one address. The leftmost `x-forwarded-for` entry
     * is one a client can choose, so it is only the fallback.
     */
    public static function clientIp(Request $request): ?string
    {
        $real = trim((string) $request->headers->get('x-real-ip', ''));
        if ($real !== '' && self::isIpAddress($real)) {
            return $real;
        }

        $forwarded = trim(explode(',', (string) $request->headers->get('x-forwarded-for', ''))[0]);
        if ($forwarded !== '' && self::isIpAddress($forwarded)) {
            return $forwarded;
        }

        return null;
    }

    /** Loose on purpose. Postgres `inet` is the real parser, and this keeps junk away from it. */
    public static function isIpAddress(string $value): bool
    {
        if (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/D', $value, $m)) {
            return (int) $m[1] <= 255 && (int) $m[2] <= 255 && (int) $m[3] <= 255 && (int) $m[4] <= 255;
        }

        return preg_match('/^[0-9a-f]{0,4}(:[0-9a-f]{0,4}){2,7}$/Di', $value) === 1;
    }

    /** Best effort. The row and the object are already written by the time this runs. */
    public static function record(Client $admin, string $assetId, ?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }
        try {
            $admin->from('asset_upload_ip')->insert(['asset_id' => $assetId, 'ip' => $ip])->execute();

            return true;
        } catch (SupabaseException) {
            return false;
        }
    }
}

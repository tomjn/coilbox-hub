<?php

namespace App\Domain\Points;

/**
 * Community points for pictures. They follow the asset row, so uploads and
 * moderation both land here through the one write hook.
 */
final class PicturePoints
{
    /**
     * @param  array<string, mixed>|null  $old  null on an insert
     * @param  array<string, mixed>  $new  the row as stored
     */
    public static function changed(?array $old, array $new): void
    {
        $subject = (string) $new['id'];
        $approved = $new['moderation'] === 'approved';
        $handedOver = $old !== null && ($old['uploaded_by'] ?? null) !== ($new['uploaded_by'] ?? null);

        if ($handedOver || ! $approved) {
            CommunityPoints::revoke(CommunityPoints::PICTURE, $subject);
            CommunityPoints::revoke(CommunityPoints::UNIT_PICTURE, $subject);
        }
        if ($approved) {
            $kind = ($new['unit_name'] ?? null) !== null ? CommunityPoints::UNIT_PICTURE : CommunityPoints::PICTURE;
            CommunityPoints::award($new['uploaded_by'] ?? null, $kind, $subject);
        }
    }
}

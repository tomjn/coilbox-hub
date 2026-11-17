<?php

namespace App\Domain\Mirrors;

final class UploadToken
{
    /** Returns the token as base64url payload, a dot, then base64url signature. */
    public static function issue(
        string $mirrorId,
        string $uploadId,
        string $sha256,
        int $size,
        string $filename,
        string $kind,
        int $startBy,
        string $secretKey,
    ): string {
        // Key order and escaping flags are part of the signed bytes, so they must not change.
        $payload = json_encode([
            'v' => 1,
            'mirror' => $mirrorId,
            'upload' => $uploadId,
            'sha256' => $sha256,
            'size' => $size,
            'filename' => $filename,
            'kind' => $kind,
            'start_by' => $startBy,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $sig = Signing::sign(Signing::CTX_TOKEN, $payload, $secretKey);

        return B64::encode($payload).'.'.B64::encode($sig);
    }
}

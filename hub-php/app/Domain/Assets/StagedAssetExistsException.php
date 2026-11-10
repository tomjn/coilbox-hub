<?php

namespace App\Domain\Assets;

use RuntimeException;

/** {@see Staging::put()} refused because an object already sits at that path. */
class StagedAssetExistsException extends RuntimeException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct($path.' already exists in the staging bucket');
    }
}

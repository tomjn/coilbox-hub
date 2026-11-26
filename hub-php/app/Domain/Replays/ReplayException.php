<?php

namespace App\Domain\Replays;

use RuntimeException;

/**
 * A file the replay reader will not read: not a replay, a format version it
 * does not know, or a file that is cut short or damaged. The reader gives no
 * partial result.
 */
final class ReplayException extends RuntimeException {}

<?php

namespace App\Domain\Gallery\Workshop;

use RuntimeException;

/** A project whose own edits would build something no slot could hold. */
final class TooLarge extends RuntimeException {}

<?php

namespace App\Domain\Maps;

use RuntimeException;

/** One map entry's refusal, carrying the message the client is sent. */
final class Refused extends RuntimeException {}

<?php

namespace Tests\Unit\Mirrors;

/** Reads the copies of the mirror repository's protocol fixtures. */
trait MirrorFixtures
{
    private static function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/mirror/'.$name);
    }

    /** @return array<string, string> */
    private static function fixtureKeys(): array
    {
        return json_decode(self::fixture('keys.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}

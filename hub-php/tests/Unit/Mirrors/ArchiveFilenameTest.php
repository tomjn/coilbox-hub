<?php

namespace Tests\Unit\Mirrors;

use App\Domain\Mirrors\ArchiveFilename;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ArchiveFilenameTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function valid(): array
    {
        return [
            'accents and brackets' => ['Test Map (v1.2) é.sd7'],
            'upper case extension' => ['x.SDZ'],
            'extra dots' => ['a.b.c.sd7'],
            'longest' => [str_repeat('a', 251).'.sd7'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function invalid(): array
    {
        return [
            'leading dot' => ['.hidden.sd7'],
            'slash' => ['a/b.sd7'],
            'backslash' => ['a\\b.sd7'],
            'nul' => ["a\0.sd7"],
            'delete' => ["a\x7f.sd7"],
            'right to left override' => ["a\u{202E}7ds.sd7"],
            'line separator' => ["a\u{2028}.sd7"],
            'byte order mark' => ["a\u{FEFF}.sd7"],
            'wrong extension' => ['map.zip'],
            'trailing newline' => ["a.sd7\n"],
            'too long' => [str_repeat('a', 252).'.sd7'],
            'invalid utf8' => ["\xff.sd7"],
            'empty' => [''],
            'extension only' => ['.sd7'],
        ];
    }

    #[DataProvider('valid')]
    public function test_valid_names(string $name): void
    {
        $this->assertTrue(ArchiveFilename::isValid($name));
    }

    #[DataProvider('invalid')]
    public function test_invalid_names(string $name): void
    {
        $this->assertFalse(ArchiveFilename::isValid($name));
    }
}

<?php

namespace Tests\Unit\Engines;

use App\Domain\Engines\EngineFilename;
use PHPUnit\Framework\TestCase;

class EngineFilenameTest extends TestCase
{
    public function test_it_reads_the_version_and_platform(): void
    {
        $this->assertSame(
            ['version' => '2026.09.03', 'platform' => 'arm64-macos'],
            EngineFilename::parse('recoil_2026.09.03_arm64-macos.7z'),
        );
        $this->assertSame(
            '2026.09.01-137-g15f18d5',
            EngineFilename::parse('recoil_2026.09.01-137-g15f18d5_amd64-linux.7z')['version'],
        );
    }

    public function test_it_rejects_anything_else(): void
    {
        $this->assertNull(EngineFilename::parse('recoil_2026.09.03_arm64-macos.sd7'));
        $this->assertNull(EngineFilename::parse('recoil_2026.09.03_riscv-linux.7z'));
        $this->assertNull(EngineFilename::parse('recoil__arm64-macos.7z'));
        $this->assertNull(EngineFilename::parse('recoil_'.str_repeat('1', 65).'_arm64-macos.7z'));
        $this->assertNull(EngineFilename::parse('recoil_a/b_arm64-macos.7z'));
        $this->assertNull(EngineFilename::parse('RECOIL_2026.09.03_arm64-macos.7z'));
    }
}

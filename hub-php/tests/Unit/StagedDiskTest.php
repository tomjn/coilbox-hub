<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StagedDiskTest extends TestCase
{
    public function test_a_new_root_is_group_readable_and_writable(): void
    {
        $base = sys_get_temp_dir().'/staged-disk-'.bin2hex(random_bytes(4));
        $root = $base.'/staged-pictures';
        mkdir($base, 0775);

        config(['filesystems.disks.staged.root' => $root]);
        Storage::forgetDisk('staged');

        $umask = umask(002);
        try {
            Storage::disk('staged')->put('a/b.webp', 'x');
            clearstatcache();
            $mode = fileperms($root) & 0777;
        } finally {
            umask($umask);
            Storage::forgetDisk('staged');
            File::deleteDirectory($base);
        }

        $this->assertSame(0770, $mode & 0770, sprintf('Root mode was %04o.', $mode));
    }
}

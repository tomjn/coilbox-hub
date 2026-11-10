<?php

namespace Tests\Unit\Container;

use App\Domain\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * Ported from `lib/container/container.test.ts`. The two cases that read
 * `supabase/migrations` (the database accepts exactly the gallery kinds) are
 * skipped, since the PHP app has no copy of the SQL. The case that checks the
 * kinds still exist upstream is kept, against `Container::KINDS`.
 */
class ContainerTest extends TestCase
{
    public function test_a_code_the_app_would_write_round_trips_here(): void
    {
        $code = Container::encodeCode('preset', Container::SUPPORTED_KIND_VERSIONS['preset'], (object) [
            'gameName' => 'Beyond All Reason',
            'mapName' => 'Comet Catcher Remake',
            'startPosType' => 2,
            'modOptionValues' => new \stdClass,
            'participants' => [],
        ]);

        $result = Container::identify($code);
        $this->assertSame('preset', $result['kind']);
        $this->assertSame('ok', $result['compatibility']);
    }

    public function test_a_container_from_a_newer_coilbox_is_flagged_not_misread(): void
    {
        $code = Container::encodeCode('preset', Container::SUPPORTED_KIND_VERSIONS['preset'] + 1, new \stdClass);

        $this->assertSame('newer', Container::identify($code)['compatibility']);
    }

    public function test_every_kind_the_gallery_carries_still_exists_upstream(): void
    {
        foreach (Container::GALLERY_KINDS as $kind) {
            $this->assertContains($kind, Container::KINDS);
        }
    }

    public function test_campaigns_are_deliberately_not_carried(): void
    {
        $this->assertNotContains('campaign', Container::GALLERY_KINDS);
    }

    public function test_the_publish_ceiling_matches_the_apps_import_ceiling(): void
    {
        $this->assertSame(512 * 1024, Container::MAX_CONTAINER_BYTES);
    }
}

<?php

namespace Tests\Unit;

use App\Support\HubCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HubCacheTest extends TestCase
{
    public function test_a_value_is_loaded_once_then_served_from_the_cache(): void
    {
        $calls = 0;
        $load = function () use (&$calls) {
            $calls++;

            return 'value';
        };
        $this->assertSame('value', HubCache::remember('k', ['items'], 60, $load));
        $this->assertSame('value', HubCache::remember('k', ['items'], 60, $load));
        $this->assertSame(1, $calls);
    }

    public function test_null_is_cached_too(): void
    {
        $calls = 0;
        HubCache::remember('k', ['items'], 60, function () use (&$calls) {
            $calls++;

            return null;
        });
        $this->assertNull(HubCache::remember('k', ['items'], 60, fn () => 'loaded again'));
        $this->assertSame(1, $calls);
    }

    public function test_invalidating_a_tag_reloads_every_entry_that_named_it(): void
    {
        HubCache::remember('a', ['items', 'item:1'], 60, fn () => 'old a');
        HubCache::remember('b', ['maps'], 60, fn () => 'old b');

        HubCache::invalidate(['item:1', 'item:1']);

        $this->assertSame('new a', HubCache::remember('a', ['items', 'item:1'], 60, fn () => 'new a'));
        $this->assertSame('old b', HubCache::remember('b', ['maps'], 60, fn () => 'new b'));
    }

    /** Memcached can evict a version key. That must never bring an old entry back. */
    public function test_an_evicted_tag_version_does_not_revive_a_stale_entry(): void
    {
        HubCache::remember('a', ['items'], 60, fn () => 'first');
        HubCache::invalidate(['items']);
        HubCache::remember('a', ['items'], 60, fn () => 'second');

        Cache::forget('hub-tag:'.sha1('items'));

        $this->assertSame('third', HubCache::remember('a', ['items'], 60, fn () => 'third'));
    }
}

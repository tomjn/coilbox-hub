<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test runs against a made up Supabase project, whatever the local
     * .env holds, and any HTTP call a test did not fake fails. So the suite
     * gives the same answer on a laptop pointed at production and in CI with
     * no settings at all, and can never touch the real database.
     */
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => 'https://project.supabase.test',
            'hub.supabase.publishable_key' => 'sb_publishable_test',
            'hub.supabase.service_role_key' => 'service-role-test',
            'app.url' => 'http://localhost',
            'cache.default' => 'array',
            'session.driver' => 'array',
            // Tests that read the catalogue from MariaDB say so.
            'hub.games_store' => 'supabase',
        ]);
        // url() and Site::url() must agree on the made up origin above.
        URL::forceRootUrl('http://localhost');
        Http::preventStrayRequests();
    }

    /**
     * Runs `$act` against a database with no tables, so every query fails the
     * way an unreachable MariaDB does. Removing a table instead would commit
     * on MariaDB and outlive the test.
     */
    protected function withBrokenDatabase(callable $act): mixed
    {
        $default = DB::getDefaultConnection();
        config(['database.connections.empty' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('empty');
        try {
            return $act();
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}

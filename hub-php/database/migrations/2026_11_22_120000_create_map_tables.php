<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maps, map authors and map packs, moved from Supabase (#599). Ids are the
 * Supabase ids, so reports and archives that still live in Supabase and name
 * a map by id keep pointing at it.
 *
 * Postgres has two partial unique indexes on `map_ownership_request`, one open
 * request per person per map and one per person per author. MariaDB has no
 * partial indexes, so `App\Supabase\Local` checks both before it writes.
 *
 * The views (`map_browse`, `map_listing`, `map_pack_entry` and the author
 * views) are worked out in PHP by `App\Supabase\Local\MapViews`.
 *
 * The hub reads Supabase until `php artisan maps:copy` has copied the rows and
 * set `data_move` to `mariadb` for `maps`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map', function (Blueprint $table) {
            self::binary($table);
            $table->char('id', 36)->primary();
            $table->string('map_name', 512)->unique('map_identity_idx');
            $table->string('slug', 512)->unique('map_slug_idx');
            $table->text('display_name')->nullable();
            $table->text('description')->nullable();
            $table->text('map_version')->nullable();
            $table->string('archive_filename', 512)->nullable();
            $table->integer('width_elmos');
            $table->integer('height_elmos');
            $table->double('world_height_min');
            $table->double('world_height_max');
            $table->double('min_wind')->nullable();
            $table->double('max_wind')->nullable();
            $table->double('tidal_strength')->nullable();
            $table->boolean('void_water')->nullable();
            $table->boolean('void_ground')->nullable();
            $table->double('water_coverage')->nullable();
            $table->json('appearance');
            $table->json('curated_tags');
            $table->text('source_hash');
            $table->text('source_archive');
            $table->integer('catalog_version');
            $table->text('facts_digest');
            $table->char('submitted_by', 36)->nullable();
            $table->dateTime('seen_at', 6);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->dateTime('featured_at', 6)->nullable();
            $table->char('featured_by', 36)->nullable();
            $table->char('owner_user_id', 36)->nullable();
            $table->index(['submitted_by', 'created_at'], 'map_submitter_recent_idx');
            $table->index('owner_user_id');
        });

        Schema::create('map_point', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('map_id', 36);
            $table->string('kind', 16);
            $table->integer('ordinal');
            $table->double('x');
            $table->double('z');
            $table->double('y')->nullable();
            $table->json('meta')->nullable();
            $table->unique(['map_id', 'kind', 'ordinal'], 'map_point_identity_idx');
            $table->foreign('map_id')->references('id')->on('map')->cascadeOnDelete();
        });

        Schema::create('map_author', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('map_id', 36);
            $table->integer('credit_index');
            $table->string('raw', 512);
            $table->string('key', 512);
            $table->unique(['map_id', 'credit_index'], 'map_author_credit_idx');
            $table->index('key', 'map_author_key_idx');
            $table->foreign('map_id')->references('id')->on('map')->cascadeOnDelete();
        });

        Schema::create('author_name', function (Blueprint $table) {
            self::binary($table);
            $table->string('key', 512)->primary();
            $table->string('name', 512);
            $table->dateTime('set_at', 6);
        });

        Schema::create('author_alias', function (Blueprint $table) {
            self::binary($table);
            $table->string('from_key', 512)->primary();
            $table->string('to_key', 512);
            $table->text('note')->nullable();
            $table->char('set_by', 36)->nullable();
            $table->dateTime('set_at', 6);
            $table->index('to_key');
        });

        Schema::create('author_owner', function (Blueprint $table) {
            self::binary($table);
            $table->string('key', 512)->primary();
            $table->char('user_id', 36);
            $table->dateTime('created_at', 6);
            $table->index('user_id', 'author_owner_user_idx');
        });

        Schema::create('map_pack', function (Blueprint $table) {
            self::binary($table);
            $table->char('id', 36)->primary();
            $table->text('title');
            $table->text('blurb')->nullable();
            $table->dateTime('featured_at', 6)->nullable();
            $table->char('featured_by', 36)->nullable();
            $table->dateTime('created_at', 6);
        });

        Schema::create('map_pack_map', function (Blueprint $table) {
            self::binary($table);
            $table->char('pack_id', 36);
            $table->string('map_name', 512);
            $table->integer('position');
            $table->primary(['pack_id', 'map_name']);
            $table->index(['pack_id', 'position'], 'map_pack_map_order_idx');
            $table->foreign('pack_id')->references('id')->on('map_pack')->cascadeOnDelete();
        });

        Schema::create('map_download_source', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('map_id', 36);
            $table->string('kind', 16);
            $table->text('value');
            $table->text('asset')->nullable();
            $table->text('filename')->nullable();
            $table->integer('sort_order')->default(0);
            $table->index(['map_id', 'sort_order', 'id'], 'map_download_source_map_idx');
            $table->foreign('map_id')->references('id')->on('map')->cascadeOnDelete();
        });

        Schema::create('map_mirror_host', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->string('name', 255)->unique('map_mirror_host_name_idx');
            $table->text('url_template');
            $table->boolean('enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->text('note')->nullable();
        });

        Schema::create('map_ownership_request', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('map_id', 36)->nullable();
            $table->string('author_key', 512)->nullable();
            $table->char('requested_by', 36);
            $table->text('requested_by_name');
            $table->text('note')->nullable();
            $table->string('state', 16);
            $table->char('decided_by', 36)->nullable();
            $table->dateTime('decided_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['state', 'created_at'], 'map_ownership_request_queue_idx');
            $table->index(['map_id', 'requested_by']);
            $table->index(['author_key', 'requested_by']);
            $table->foreign('map_id')->references('id')->on('map')->cascadeOnDelete();
        });

        Schema::create('map_source_conflict', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('map_id', 36);
            $table->text('source_archive');
            $table->string('held_source_hash', 255);
            $table->string('reported_source_hash', 255);
            $table->char('reported_by', 36)->nullable();
            $table->dateTime('at', 6);
            $table->unique(['map_id', 'reported_source_hash'], 'map_source_conflict_report_idx');
            $table->foreign('map_id')->references('id')->on('map');
        });
    }

    public function down(): void
    {
        foreach ([
            'map_source_conflict', 'map_ownership_request', 'map_mirror_host', 'map_download_source', 'map_pack_map', 'map_pack',
            'author_owner', 'author_alias', 'author_name', 'map_author', 'map_point', 'map',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('data_move')->where('area', 'maps')->delete();
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

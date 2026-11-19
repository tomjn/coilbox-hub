<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The hub mirrors, their archives and the signed catalogue, moved from
 * Supabase (#600). Ids are the Supabase ids.
 *
 * Postgres keeps a failed upload's hash, file name and name free for another
 * attempt with three partial unique indexes. MariaDB has no partial indexes,
 * so `live` is 1 for every archive that has not failed and null for one that
 * has, and each unique key ends in it. Rows with a null never clash.
 *
 * The functions that write these tables are done in PHP by
 * `App\Supabase\Local\MirrorFunctions`. The hub reads Supabase until
 * `php artisan mirrors:copy` has copied the rows and set `data_move` to
 * `mariadb` for `mirrors`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mirror', function (Blueprint $table) {
            self::binary($table);
            $table->string('id', 64)->primary();
            $table->string('name', 128);
            $table->text('base_url');
            $table->string('public_key', 43);
            $table->boolean('enabled')->default(true);
            $table->boolean('healthy')->default(false);
            $table->dateTime('last_report_at', 6)->nullable();
            $table->bigInteger('last_report_sent_at')->nullable();
            $table->bigInteger('last_catalogue_seq')->nullable();
            $table->bigInteger('last_catalogue_generated_at')->nullable();
            $table->dateTime('last_check_at', 6)->nullable();
            $table->dateTime('created_at', 6);
        });

        Schema::create('archive', function (Blueprint $table) {
            self::binary($table);
            $table->char('id', 36)->primary();
            $table->char('sha256', 64);
            $table->string('filename', 255);
            $table->string('filename_lower', 255);
            $table->string('kind', 8);
            $table->string('internal_name', 256);
            $table->string('version', 256);
            $table->string('shortname', 64)->nullable();
            $table->string('author', 256)->nullable();
            $table->text('description')->nullable();
            $table->bigInteger('size');
            $table->char('uploader', 36)->nullable();
            $table->string('state', 16)->default('uploading');
            $table->char('game_id', 36)->nullable();
            $table->string('upload_mirror_id', 64)->nullable();
            $table->dateTime('reserved_until', 6)->nullable();
            $table->dateTime('listed_at', 6)->nullable();
            $table->dateTime('removed_at', 6)->nullable();
            $table->string('removal_reason', 200)->nullable();
            $table->char('removed_by', 36)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->text('removal_note')->nullable();
            $table->tinyInteger('live')->nullable()->storedAs("case when state <> 'failed' then 1 end");
            $table->unique(['sha256', 'live'], 'archive_sha256_idx');
            $table->unique(['filename_lower', 'live'], 'archive_filename_idx');
            $table->unique(['internal_name', 'version', 'live'], 'archive_name_version_idx');
            $table->index(['game_id', 'state', 'listed_at'], 'archive_game_listed_idx');
            $table->index(['kind', 'internal_name', 'state'], 'archive_kind_name_listed_idx');
            $table->index(['uploader', 'state']);
            $table->foreign('upload_mirror_id')->references('id')->on('mirror');
        });

        Schema::create('archive_mirror', function (Blueprint $table) {
            self::binary($table);
            $table->char('archive_id', 36);
            $table->string('mirror_id', 64);
            $table->dateTime('first_seen_at', 6);
            $table->dateTime('last_seen_at', 6);
            $table->primary(['archive_id', 'mirror_id']);
            $table->index('mirror_id');
            $table->foreign('archive_id')->references('id')->on('archive')->cascadeOnDelete();
            $table->foreign('mirror_id')->references('id')->on('mirror')->cascadeOnDelete();
        });

        Schema::create('archive_mismatch', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('archive_id', 36);
            $table->json('found');
            $table->string('ip', 64)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('handled_at', 6)->nullable();
            $table->char('handled_by', 36)->nullable();
            $table->string('handled_how', 16)->nullable();
            $table->index(['archive_id', 'handled_at'], 'archive_mismatch_open_idx');
            $table->index(['ip', 'created_at'], 'archive_mismatch_ip_idx');
            $table->foreign('archive_id')->references('id')->on('archive')->cascadeOnDelete();
        });

        // One row. See `public.catalogue_state` in 20261117120000_hub_mirrors.sql.
        Schema::create('catalogue_state', function (Blueprint $table) {
            self::binary($table);
            $table->boolean('id')->primary();
            $table->bigInteger('next_seq')->default(1);
            $table->bigInteger('changes')->default(0);
            $table->bigInteger('built_changes')->default(-1);
            $table->bigInteger('seq')->nullable();
            $table->bigInteger('generated_at')->nullable();
            $table->longText('body')->nullable();
            $table->text('sig')->nullable();
            $table->string('next_key_public', 64)->nullable();
            $table->bigInteger('next_key_since_seq')->nullable();
        });

        DB::table('catalogue_state')->insert(['id' => true]);
    }

    public function down(): void
    {
        foreach (['archive_mismatch', 'archive_mirror', 'archive', 'mirror', 'catalogue_state'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('data_move')->where('area', 'mirrors')->delete();
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

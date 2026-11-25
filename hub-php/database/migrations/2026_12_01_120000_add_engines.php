<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What engine uploads need. An engine version ships once per platform, so the
 * archive name key gains `platform`. Other kinds leave it empty and keep their
 * old key. Only people in `engine_uploader` may list engines, and a mirror
 * says whether it holds them with `takes_engines`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engine_uploader', function (Blueprint $table) {
            self::binary($table);
            $table->char('user_id', 36)->primary();
            $table->char('granted_by', 36);
            $table->dateTime('granted_at', 6);
        });

        Schema::table('archive', function (Blueprint $table) {
            $table->dropUnique('archive_name_version_idx');
        });
        Schema::table('archive', function (Blueprint $table) {
            $table->string('platform', 16)->default('');
            $table->unique(['internal_name', 'version', 'platform', 'live'], 'archive_name_version_idx');
        });

        Schema::table('mirror', function (Blueprint $table) {
            $table->boolean('takes_engines')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('mirror', function (Blueprint $table) {
            $table->dropColumn('takes_engines');
        });

        Schema::table('archive', function (Blueprint $table) {
            $table->dropUnique('archive_name_version_idx');
        });
        Schema::table('archive', function (Blueprint $table) {
            $table->dropColumn('platform');
            $table->unique(['internal_name', 'version', 'live'], 'archive_name_version_idx');
        });

        Schema::dropIfExists('engine_uploader');
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

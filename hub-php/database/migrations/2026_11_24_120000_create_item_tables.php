<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Published items, reports, profiles and moderator capabilities, moved from
 * Supabase (#601). Ids are the Supabase ids. Accounts are still Supabase Auth
 * users, so `author_id`, `user_id` and the other user columns hold their ids
 * with no foreign key.
 *
 * `item.search` is not stored: the local store searches titles and
 * descriptions in PHP. `item.mode` is written by PHP from the container, as
 * Postgres generated it. `profile.name_key` keeps display names unique in any
 * mix of capital and small letters, as `profile_display_name_unique` did.
 *
 * The rules Postgres enforced with row level security, triggers and functions
 * are done in PHP by `App\Supabase\Local`. The hub reads Supabase until
 * `php artisan items:copy` has copied the rows and set `data_move` to
 * `mariadb` for `items`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item', function (Blueprint $table) {
            self::binary($table);
            $table->char('id', 36)->primary();
            $table->string('kind', 32);
            $table->integer('kind_version');
            $table->text('title');
            $table->text('description');
            $table->text('game_name')->nullable();
            $table->text('map_name')->nullable();
            $table->json('tags');
            $table->json('container');
            $table->char('author_id', 36);
            $table->text('author_name');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->dateTime('deleted_at', 6)->nullable();
            $table->text('mode')->nullable();
            $table->text('game_key')->nullable();
            $table->integer('import_count')->default(0);
            $table->dateTime('featured_at', 6)->nullable();
            $table->char('featured_by', 36)->nullable();
            $table->index('author_id', 'item_author_idx');
            $table->index(['deleted_at', 'created_at'], 'item_live_created_idx');
            $table->index(['deleted_at', 'featured_at'], 'item_live_featured_idx');
        });

        Schema::create('report', function (Blueprint $table) {
            self::binary($table);
            $table->char('id', 36)->primary();
            $table->char('item_id', 36)->nullable();
            $table->char('reporter_id', 36)->nullable();
            $table->text('reason');
            $table->dateTime('created_at', 6);
            $table->dateTime('handled_at', 6)->nullable();
            $table->char('handled_by', 36)->nullable();
            $table->char('map_id', 36)->nullable();
            $table->string('kind', 32)->nullable();
            $table->index('item_id', 'report_item_idx');
            $table->index('map_id', 'report_map_idx');
            $table->index(['handled_at', 'created_at'], 'report_open_idx');
            $table->foreign('item_id')->references('id')->on('item')->cascadeOnDelete();
            $table->foreign('map_id')->references('id')->on('map')->cascadeOnDelete();
        });

        Schema::create('profile', function (Blueprint $table) {
            self::binary($table);
            $table->char('user_id', 36)->primary();
            $table->string('display_name', 255);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->string('name_key', 255)->storedAs('lower(display_name)');
            $table->unique('name_key', 'profile_display_name_unique');
        });

        Schema::create('user_capability', function (Blueprint $table) {
            self::binary($table);
            $table->char('user_id', 36);
            $table->string('capability', 64);
            $table->dateTime('granted_at', 6);
            $table->char('granted_by', 36)->nullable();
            $table->primary(['user_id', 'capability']);
        });
    }

    public function down(): void
    {
        foreach (['report', 'item', 'profile', 'user_capability'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('data_move')->where('area', 'items')->delete();
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

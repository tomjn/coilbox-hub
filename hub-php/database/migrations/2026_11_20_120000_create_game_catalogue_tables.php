<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The game catalogue, moved from Supabase (#597). Ids are the Supabase ids, so
 * rows that still live in Supabase and name a game by id keep pointing at it.
 *
 * Text compares case sensitively, as it does in Postgres, so `BYAR` and `byar`
 * are two games. Postgres arrays and jsonb are JSON here.
 *
 * Postgres has two partial unique indexes on these tables, one open ownership
 * request per person per game and one held offer per source. MariaDB has no
 * partial indexes, so `App\Supabase\Local` checks both before it writes.
 *
 * `data_move` records which database holds an area. The hub reads Supabase
 * until `php artisan games:copy` has copied the rows and set it to `mariadb`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_move', function (Blueprint $table) {
            $table->string('area', 32)->primary();
            $table->string('state', 16);
            $table->dateTime('changed_at', 6);
        });

        Schema::create('game', function (Blueprint $table) {
            self::binary($table);
            $table->char('id', 36)->primary();
            $table->string('shortname', 255)->unique('game_shortname_idx');
            $table->text('display_name')->nullable();
            $table->text('description')->nullable();
            $table->json('links');
            $table->json('start_units')->nullable();
            $table->char('submitted_by', 36)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->char('owner_user_id', 36)->nullable();
            $table->text('logo_path')->nullable();
            $table->text('logo_hash')->nullable();
            $table->text('banner_path')->nullable();
            $table->text('banner_hash')->nullable();
            $table->dateTime('hidden_at', 6)->nullable();
            $table->char('hidden_by', 36)->nullable();
            $table->string('logo_staged_tier', 8)->nullable();
            $table->string('banner_staged_tier', 8)->nullable();
            $table->dateTime('featured_at', 6)->nullable();
            $table->char('featured_by', 36)->nullable();
            $table->text('card_path')->nullable();
            $table->text('card_hash')->nullable();
            $table->string('card_staged_tier', 8)->nullable();
            $table->json('conquest_factions');
            $table->string('base_game', 255)->nullable();
            $table->index('owner_user_id');
        });

        Schema::create('game_version', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('game_id', 36);
            $table->string('version', 255);
            $table->dateTime('first_seen_at', 6);
            $table->dateTime('last_seen_at', 6);
            $table->dateTime('hidden_at', 6)->nullable();
            $table->char('hidden_by', 36)->nullable();
            $table->boolean('reported_in_full')->default(false);
            $table->unique(['game_id', 'version'], 'game_version_identity_idx');
            $table->foreign('game_id')->references('id')->on('game')->cascadeOnDelete();
        });

        Schema::create('game_faction', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('game_id', 36);
            $table->string('key', 255);
            $table->text('name');
            $table->text('logo_path')->nullable();
            $table->text('logo_hash')->nullable();
            $table->unique(['game_id', 'key'], 'game_faction_identity_idx');
            $table->foreign('game_id')->references('id')->on('game')->cascadeOnDelete();
        });

        Schema::create('game_unit', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('game_id', 36);
            $table->string('unit_name', 255);
            $table->text('full_name')->nullable();
            $table->string('faction_key', 255)->nullable();
            $table->json('build_options');
            $table->json('stats');
            $table->text('facts_digest');
            $table->text('source_version')->nullable();
            $table->dateTime('removed_at', 6)->nullable();
            $table->dateTime('last_seen_at', 6);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->text('snippet')->nullable();
            $table->json('morph_targets');
            $table->unique(['game_id', 'unit_name'], 'game_unit_identity_idx');
            $table->index(['game_id', 'faction_key'], 'game_unit_faction_idx');
            $table->foreign('game_id')->references('id')->on('game')->cascadeOnDelete();
        });

        Schema::create('game_unit_revision', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->unsignedBigInteger('unit_id');
            $table->string('version', 255);
            $table->text('full_name')->nullable();
            $table->string('faction_key', 255)->nullable();
            $table->json('build_options');
            $table->json('stats');
            $table->text('facts_digest');
            $table->dateTime('recorded_at', 6);
            $table->json('morph_targets');
            $table->unique(['unit_id', 'version'], 'game_unit_revision_identity_idx');
            $table->foreign('unit_id')->references('id')->on('game_unit')->cascadeOnDelete();
        });

        Schema::create('game_download_source', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('game_id', 36);
            $table->string('kind', 16);
            $table->text('value');
            $table->text('asset')->nullable();
            $table->text('filename')->nullable();
            $table->integer('sort_order')->default(0);
            $table->index(['game_id', 'sort_order'], 'game_download_source_game_idx');
            $table->foreign('game_id')->references('id')->on('game')->cascadeOnDelete();
        });

        Schema::create('game_download_offer', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('game_id', 36);
            $table->string('kind', 16);
            $table->text('value');
            $table->text('asset')->nullable();
            $table->text('filename')->nullable();
            $table->char('offered_by', 36);
            $table->string('state', 16)->default('held');
            $table->char('decided_by', 36)->nullable();
            $table->dateTime('decided_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['game_id', 'state'], 'game_download_offer_game_idx');
            $table->index(['state', 'created_at'], 'game_download_offer_queue_idx');
            $table->foreign('game_id')->references('id')->on('game')->cascadeOnDelete();
        });

        Schema::create('game_ownership_request', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('game_id', 36);
            $table->char('requested_by', 36);
            $table->text('requested_by_name');
            $table->text('note')->nullable();
            $table->string('state', 16)->default('open');
            $table->char('decided_by', 36)->nullable();
            $table->dateTime('decided_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['game_id', 'requested_by', 'state'], 'game_ownership_request_open_idx');
            $table->index(['state', 'created_at'], 'game_ownership_request_queue_idx');
            $table->foreign('game_id')->references('id')->on('game')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_ownership_request');
        Schema::dropIfExists('game_download_offer');
        Schema::dropIfExists('game_download_source');
        Schema::dropIfExists('game_unit_revision');
        Schema::dropIfExists('game_unit');
        Schema::dropIfExists('game_faction');
        Schema::dropIfExists('game_version');
        Schema::dropIfExists('game');
        Schema::dropIfExists('data_move');
    }

    /** Case sensitive text on MariaDB. SQLite compares bytes already. */
    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

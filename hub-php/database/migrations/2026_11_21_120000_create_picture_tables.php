<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Picture records and the staging bucket's object list, moved from Supabase
 * (#598). Ids are the Supabase ids.
 *
 * `staged_object` stands in for Supabase's `storage.objects`: one row per file
 * on the `staged` disk, so the bucket's size and the unclaimed objects are one
 * query each.
 *
 * Postgres has partial unique indexes on `asset`, one per kind of identity.
 * A MariaDB unique index ignores rows with a null in it, which gives the same
 * rule here, because each identity's own column is null on the other kinds.
 *
 * The hub reads Supabase until `php artisan pictures:copy` has copied the rows
 * and the objects and set `data_move` to `mariadb` for `pictures`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset', function (Blueprint $table) {
            self::binary($table);
            $table->char('id', 36)->primary();
            $table->string('game', 255)->nullable();
            $table->string('unit_name', 255)->nullable();
            $table->string('map_name', 512)->nullable();
            $table->string('variant', 255);
            $table->text('source_hash');
            $table->text('hash');
            $table->text('encode_profile');
            $table->string('path', 512);
            $table->string('origin', 16);
            $table->string('tier', 16);
            $table->text('mime');
            $table->integer('bytes');
            $table->integer('width');
            $table->integer('height');
            $table->integer('map_width')->nullable();
            $table->integer('map_height')->nullable();
            $table->text('source_archive');
            $table->dateTime('seen_at', 6);
            $table->dateTime('promoted_at', 6)->nullable();
            $table->char('uploaded_by', 36)->nullable();
            $table->string('moderation', 16);
            $table->string('approval_source', 16)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->double('world_height_min')->nullable();
            $table->double('world_height_max')->nullable();
            $table->string('rejection_kind', 16)->nullable();
            $table->string('blob_path', 512)->nullable();
            $table->string('blob_path_tier', 16)->nullable();
            $table->dateTime('bytes_missing_at', 6)->nullable();
            $table->string('faction_key', 255)->nullable();
            $table->unique(['game', 'unit_name', 'variant'], 'asset_unit_identity_idx');
            $table->unique(['game', 'faction_key', 'variant'], 'asset_faction_identity_idx');
            $table->unique(['map_name', 'variant'], 'asset_map_identity_idx');
            $table->index('path');
            $table->index('blob_path');
            $table->index('uploaded_by');
            $table->index(['moderation', 'seen_at']);
        });

        Schema::create('asset_event', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('asset_id', 36);
            $table->string('action', 32);
            $table->string('rejection_kind', 16)->nullable();
            $table->char('actor', 36)->nullable();
            $table->char('uploader', 36)->nullable();
            $table->dateTime('at', 6);
            $table->text('uploader_display_name')->nullable();
            $table->text('uploader_provider_name')->nullable();
            $table->index('actor', 'asset_event_actor_idx');
            $table->index('at', 'asset_event_at_idx');
            $table->index('uploader', 'asset_event_uploader_idx');
            $table->foreign('asset_id')->references('id')->on('asset');
        });

        Schema::create('asset_licence', function (Blueprint $table) {
            self::binary($table);
            $table->char('id', 36)->primary();
            $table->string('game', 255)->nullable()->unique('asset_licence_game_idx');
            $table->string('map_name', 512)->nullable()->unique('asset_licence_map_idx');
            $table->text('licence')->nullable();
            $table->text('licence_url')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('checked_at', 6);
            $table->text('checked_by');
            $table->string('redistribute_extracted', 16);
            $table->string('redistribute_rendered', 16);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->text('decision')->nullable();
            $table->dateTime('decided_at', 6)->nullable();
            $table->boolean('all_maps')->nullable()->unique('asset_licence_all_maps_idx');
        });

        Schema::create('asset_source_conflict', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('asset_id', 36);
            $table->text('source_archive');
            $table->text('held_source_hash');
            $table->string('reported_source_hash', 255);
            $table->char('reported_by', 36)->nullable();
            $table->dateTime('at', 6);
            $table->dateTime('acknowledged_at', 6)->nullable();
            $table->char('acknowledged_by', 36)->nullable();
            $table->unique(['asset_id', 'reported_source_hash'], 'asset_source_conflict_report_idx');
            $table->foreign('asset_id')->references('id')->on('asset');
        });

        Schema::create('asset_upload_ip', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('asset_id', 36)->index('asset_upload_ip_asset_idx');
            $table->string('ip', 64);
            $table->dateTime('at', 6);
            $table->foreign('asset_id')->references('id')->on('asset');
        });

        Schema::create('asset_withdrawal', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('asset_id', 36)->unique('asset_withdrawal_asset_idx');
            $table->text('path');
            $table->dateTime('at', 6);
            $table->dateTime('withdrawn_at', 6)->nullable();
            $table->foreign('asset_id')->references('id')->on('asset');
        });

        Schema::create('staged_object_deletion', function (Blueprint $table) {
            self::binary($table);
            $table->string('path', 512)->primary();
            $table->dateTime('reserved_at', 6);
        });

        Schema::create('staged_object', function (Blueprint $table) {
            self::binary($table);
            $table->string('path', 512)->primary();
            $table->bigInteger('bytes');
            $table->string('mime', 255)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['created_at', 'path']);
        });
    }

    public function down(): void
    {
        foreach (['staged_object', 'staged_object_deletion', 'asset_withdrawal', 'asset_upload_ip', 'asset_source_conflict', 'asset_licence', 'asset_event', 'asset'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('data_move')->where('area', 'pictures')->delete();
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

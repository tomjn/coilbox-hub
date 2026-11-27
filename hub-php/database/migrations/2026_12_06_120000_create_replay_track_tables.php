<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replay tracks from Coilbox (#665), one per replay and track format version.
 * A refused one leaves only a `replay_track_refusal` row, so another sender can
 * take its place. Its file goes in `replay_removal` with the kind `track`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replay_track', function (Blueprint $table) {
            self::binary($table);
            $table->char('game_id', 32);
            // The version in the track's header.
            $table->unsignedSmallInteger('format_version');
            // uploading, then checking once a mirror holds the file, then listed. removed when its replay is.
            $table->string('state', 16);
            $table->char('uploader', 36);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->string('upload_mirror_id', 64)->nullable();
            // New for each reservation, so a mirror never resumes another sender's half-sent file.
            $table->char('upload_id', 36);
            // Coilbox raises this when its recorder changes. Read from the file by the check.
            $table->unsignedInteger('recorder_version')->nullable();
            $table->dateTime('reserved_until', 6)->nullable();
            $table->dateTime('received_at', 6)->nullable();
            $table->dateTime('checked_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->primary(['game_id', 'format_version']);
            $table->index(['upload_mirror_id', 'state']);
            $table->index('sha256');
            $table->foreign('game_id')->references('game_id')->on('replay')->cascadeOnDelete();
        });

        Schema::create('replay_track_refusal', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('game_id', 32);
            $table->unsignedSmallInteger('format_version');
            $table->char('sha256', 64);
            $table->char('uploader', 36);
            // A short code, such as team_mismatch.
            $table->string('reason', 32);
            // What a moderator reads: the first difference the check found.
            $table->text('detail');
            $table->dateTime('refused_at', 6);
            $table->index('game_id');
        });

        Schema::table('replay_removal', function (Blueprint $table) {
            // replay or track. The default covers rows the live release writes during a deploy.
            $table->string('kind', 8)->default('replay');
        });

        Schema::table('replay_team', function (Blueprint $table) {
            // The team leader's player name, which a track must repeat. Empty when the start script gives none.
            $table->string('leader_name', 255)->nullable();
        });

        Schema::table('mirror', function (Blueprint $table) {
            $table->boolean('takes_tracks')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('mirror', function (Blueprint $table) {
            $table->dropColumn('takes_tracks');
        });
        Schema::table('replay_team', function (Blueprint $table) {
            $table->dropColumn('leader_name');
        });
        Schema::table('replay_removal', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
        Schema::dropIfExists('replay_track_refusal');
        Schema::dropIfExists('replay_track');
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replay analyses from Coilbox (#664). Every one sent is a row in
 * `replay_analysis_submission` until its check decides it. The one a replay
 * keeps, from the newest logger version that passed, is in `replay_analysis`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('replay_team', function (Blueprint $table) {
            // How many statistics samples the end of game block has for the team. An analysis must have the same.
            $table->unsignedInteger('samples')->nullable();
        });

        Schema::create('replay_analysis_submission', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('game_id', 32);
            $table->char('user_id', 36);
            // Coilbox's LOGGER_VERSION for the run. The log does not hold it.
            $table->unsignedInteger('logger_version');
            $table->char('sha256', 64);
            $table->unsignedInteger('size');
            // The log while it waits for its check. Cleared once checked, as the kept one is copied to replay_analysis.
            $table->longText('events')->nullable();
            // checking, kept, agreed, older (passed, but the replay keeps a newer logger version's) or refused.
            $table->string('state', 8);
            // A short code for a refusal, such as total_mismatch.
            $table->string('reason', 32)->nullable();
            $table->text('detail')->nullable();
            $table->dateTime('sent_at', 6);
            $table->dateTime('checked_at', 6)->nullable();
            $table->index(['game_id', 'state']);
            $table->foreign('game_id')->references('game_id')->on('replay')->cascadeOnDelete();
        });

        Schema::create('replay_analysis', function (Blueprint $table) {
            self::binary($table);
            $table->char('game_id', 32)->primary();
            $table->unsignedInteger('logger_version');
            $table->unsignedBigInteger('submission_id');
            $table->char('sha256', 64);
            // The run's events.jsonl as Coilbox's replay logger wrote it.
            $table->longText('events');
            // Lines of each kind, as Coilbox counts them.
            $table->json('counts');
            $table->dateTime('kept_at', 6);
            $table->foreign('game_id')->references('game_id')->on('replay')->cascadeOnDelete();
            $table->foreign('submission_id')->references('id')->on('replay_analysis_submission')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replay_analysis');
        Schema::dropIfExists('replay_analysis_submission');
        Schema::table('replay_team', function (Blueprint $table) {
            $table->dropColumn('samples');
        });
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

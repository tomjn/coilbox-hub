<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replay files the catalogue tells mirrors to delete (#676). Kept apart from
 * `replay`, because a refused replay frees its game id and the removal has to
 * stay after that row is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replay_removal', function (Blueprint $table) {
            // Compared with `replay.sha256` and `archive.sha256`, so the same collation as both.
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_bin';
            }
            $table->char('sha256', 64)->primary();
            $table->char('game_id', 32);
            // Public. It goes into the catalogue.
            $table->string('reason', 200);
            $table->dateTime('removed_at', 6);
        });

        Schema::table('replay', function (Blueprint $table) {
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::table('replay', function (Blueprint $table) {
            $table->dropIndex(['sha256']);
        });
        Schema::dropIfExists('replay_removal');
    }
};

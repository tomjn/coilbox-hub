<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports on shared replays, and who took a replay down (#671). A report names
 * an item, a map or a replay. Supabase's `report` table has no `replay_id`, so
 * replay reports only work once reports are in MariaDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report', function (Blueprint $table) {
            $table->char('replay_id', 32)->nullable();
            $table->index('replay_id', 'report_replay_idx');
            $table->foreign('replay_id')->references('game_id')->on('replay')->cascadeOnDelete();
        });
        Schema::table('replay', function (Blueprint $table) {
            $table->char('removed_by', 36)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('replay', function (Blueprint $table) {
            $table->dropColumn('removed_by');
        });
        Schema::table('report', function (Blueprint $table) {
            $table->dropForeign(['replay_id']);
            $table->dropIndex('report_replay_idx');
            $table->dropColumn('replay_id');
        });
    }
};

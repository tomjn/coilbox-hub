<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A track from a newer recorder version replaces the listed one once it passes
 * its check (#687). Until then it is a second row for the same replay and
 * format, with `replacing` set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('replay_track', function (Blueprint $table) {
            $table->boolean('replacing')->default(false);
            // The foreign key on game_id needs an index of its own once the primary key changes.
            $table->index('game_id');
        });
        Schema::table('replay_track', function (Blueprint $table) {
            $table->dropPrimary();
            $table->primary(['game_id', 'format_version', 'replacing']);
        });

        Schema::table('mirror', function (Blueprint $table) {
            // Its last report said it can replace a track it holds (tomjn/coilbox-mirror).
            $table->boolean('replaces_tracks')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('mirror', function (Blueprint $table) {
            $table->dropColumn('replaces_tracks');
        });
        DB::table('replay_track')->where('replacing', true)->delete();
        Schema::table('replay_track', function (Blueprint $table) {
            $table->dropPrimary();
            $table->primary(['game_id', 'format_version']);
        });
        Schema::table('replay_track', function (Blueprint $table) {
            $table->dropIndex(['game_id']);
            $table->dropColumn('replacing');
        });
    }
};

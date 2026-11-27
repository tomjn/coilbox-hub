<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the replay page (#667) draws that the check did not keep. Replays
 * checked before this have nulls here until `php artisan replays:page-facts`
 * reads their files again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('replay', function (Blueprint $table) {
            // Each ally team's start box as fractions of the map, from the start script. An empty list when it sets none.
            $table->json('start_boxes')->nullable();
            // Where orders were aimed, counted on Coilbox's 256 cell grid (map_grids.rs). Null when the map's size is not known.
            $table->json('order_heat')->nullable();
            $table->dateTime('page_facts_at', 6)->nullable();
        });

        Schema::table('replay_team', function (Blueprint $table) {
            // The start script's colour as #rrggbb.
            $table->string('colour', 7)->nullable();
            // Every statistics sample as columns, null when the engine took one or none.
            $table->json('history')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('replay_team', function (Blueprint $table) {
            $table->dropColumn(['colour', 'history']);
        });
        Schema::table('replay', function (Blueprint $table) {
            $table->dropColumn(['start_boxes', 'order_heat', 'page_facts_at']);
        });
    }
};

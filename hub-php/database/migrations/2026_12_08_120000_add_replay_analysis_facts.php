<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the replay page (#668) draws from a kept analysis, worked out once when
 * it is kept. Analyses kept before this have null here until
 * `php artisan replays:analysis-facts` works them out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('replay_analysis', function (Blueprint $table) {
            // AnalysisFacts::of: heat cells, living value, finished units, kills, losses and awards.
            $table->json('facts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('replay_analysis', function (Blueprint $table) {
            $table->dropColumn('facts');
        });
    }
};

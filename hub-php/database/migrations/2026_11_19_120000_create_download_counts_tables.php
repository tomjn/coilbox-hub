<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The download counters, moved from Supabase while they were still empty.
 * `target_id` is the Supabase id of the map, game or item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_day', function (Blueprint $table) {
            $table->string('kind', 8);
            $table->uuid('target_id');
            $table->date('day');
            $table->unsignedInteger('count')->default(0);
            $table->primary(['kind', 'target_id', 'day']);
            // The popular sorts read one kind over a window of days.
            $table->index(['kind', 'day']);
        });

        Schema::create('download_seen', function (Blueprint $table) {
            $table->date('day');
            $table->char('seen_key', 64);
            $table->primary(['day', 'seen_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_day');
        Schema::dropIfExists('download_seen');
    }
};

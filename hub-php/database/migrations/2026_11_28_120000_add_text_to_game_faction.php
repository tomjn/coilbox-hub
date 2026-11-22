<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A faction's own words, written by the game's owner or a moderator. The
 * tagline is one plain line. The description is Markdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_faction', function (Blueprint $table) {
            $table->text('tagline')->nullable();
            $table->text('description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('game_faction', function (Blueprint $table) {
            $table->dropColumn(['tagline', 'description']);
        });
    }
};

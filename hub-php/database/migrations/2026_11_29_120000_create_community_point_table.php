<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Community points, one row per award. `subject` names what earned it, such as
 * a game shortname or an item id, so the same contribution cannot pay twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_point', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('user_id', 36);
            $table->string('kind', 32);
            $table->string('subject', 255);
            $table->unsignedSmallInteger('points');
            $table->dateTime('created_at', 6);
            $table->unique(['user_id', 'kind', 'subject'], 'community_point_award_idx');
            $table->index('user_id', 'community_point_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_point');
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

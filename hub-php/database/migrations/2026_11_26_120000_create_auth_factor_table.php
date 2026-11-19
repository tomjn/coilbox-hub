<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authenticator apps for accounts once sign in runs on the hub (#602, part
 * 3b). Supabase's factors cannot be copied, because its admin API does not
 * give out their secrets, so a moderator who had one sets it up again.
 *
 * `secret` is encrypted with the app key. `last_step` is the newest 30 second
 * window a code was accepted for, so one code cannot be used twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_factor', function (Blueprint $table) {
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_bin';
            }
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->string('friendly_name', 255);
            $table->text('secret');
            $table->string('status', 16);
            $table->bigInteger('last_step')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index('user_id', 'auth_factor_user_idx');
            $table->foreign('user_id')->references('id')->on('auth_user')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_factor');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Requests from signed in users to add a mirror, waiting for a moderator.
 * One row per public key, so asking again replaces the earlier request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mirror_request', function (Blueprint $table) {
            self::binary($table);
            $table->string('public_key', 43)->primary();
            $table->string('mirror_id', 64);
            $table->string('name', 128);
            $table->text('base_url');
            $table->char('requested_by', 36);
            $table->dateTime('requested_at', 6);
            $table->dateTime('checked_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mirror_request');
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

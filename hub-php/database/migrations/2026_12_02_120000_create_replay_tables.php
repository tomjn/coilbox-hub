<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Match replays sent from Coilbox (#662), one row per match keyed by the
 * engine's game id, and everyone who sent each one. A mirror says whether it
 * takes replays with `takes_replays`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replay', function (Blueprint $table) {
            self::binary($table);
            // The 16 byte game id from the replay's header, as 32 lower case hex digits.
            $table->char('game_id', 32)->primary();
            // uploading, then checking once a mirror holds the file. The check in #663 moves it on.
            $table->string('state', 16);
            $table->char('uploader', 36);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->string('upload_mirror_id', 64)->nullable();
            // New for each reservation, so a mirror never resumes another sender's half-sent file.
            $table->char('upload_id', 36);
            // What Coilbox sent, kept until #663 checks it against the file.
            $table->longText('summary');
            $table->dateTime('reserved_until', 6)->nullable();
            $table->dateTime('received_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['upload_mirror_id', 'state']);
        });

        Schema::create('replay_sender', function (Blueprint $table) {
            self::binary($table);
            $table->char('game_id', 32);
            $table->char('user_id', 36);
            // The name the sender says they were in the battle under.
            $table->string('name', 255);
            $table->dateTime('sent_at', 6);
            $table->primary(['game_id', 'user_id']);
            $table->foreign('game_id')->references('game_id')->on('replay')->cascadeOnDelete();
        });

        Schema::table('mirror', function (Blueprint $table) {
            $table->boolean('takes_replays')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('mirror', function (Blueprint $table) {
            $table->dropColumn('takes_replays');
        });
        Schema::dropIfExists('replay_sender');
        Schema::dropIfExists('replay');
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

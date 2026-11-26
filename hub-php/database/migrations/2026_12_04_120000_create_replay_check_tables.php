<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the replay check in #663 keeps. A replay that passes gets its facts on
 * `replay` and a row per team, per seat and per chat message. A refused one
 * leaves only a `replay_refusal` row, which outlives the replay so its game id
 * can be sent again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('replay', function (Blueprint $table) {
            // The start script's game type, split at the release the catalogue knows, else at the last space.
            $table->string('game', 255)->nullable();
            $table->string('game_version', 255)->nullable();
            $table->string('map', 255)->nullable();
            $table->string('engine', 255)->nullable();
            $table->dateTime('started_at')->nullable();
            // Game seconds.
            $table->unsignedInteger('duration')->nullable();
            // Winning ally team numbers.
            $table->json('winners')->nullable();
            // Players, not spectators or AIs.
            $table->unsignedSmallInteger('player_count')->nullable();
            // Pause, leave, resign, team and game over events from the file.
            $table->json('events')->nullable();
            // Data only one game records, keyed by the decoder that read it, such as `bar.awards`.
            $table->json('extra')->nullable();
            $table->dateTime('checked_at', 6)->nullable();
            $table->index(['state', 'started_at']);
            $table->index(['state', 'map']);
            $table->index(['state', 'game']);
        });

        Schema::create('replay_team', function (Blueprint $table) {
            self::binary($table);
            $table->char('game_id', 32);
            $table->unsignedSmallInteger('team');
            $table->unsignedSmallInteger('ally_team')->nullable();
            $table->boolean('won');
            // The team's last statistics sample, null when the engine took none.
            foreach (['metal_used', 'energy_used', 'metal_produced', 'energy_produced', 'metal_excess', 'energy_excess',
                'metal_received', 'energy_received', 'metal_sent', 'energy_sent', 'damage_dealt', 'damage_received'] as $float) {
                $table->double($float)->nullable();
            }
            foreach (['units_produced', 'units_died', 'units_received', 'units_sent', 'units_captured', 'units_out_captured', 'units_killed'] as $int) {
                $table->integer($int)->nullable();
            }
            $table->double('start_x')->nullable();
            $table->double('start_z')->nullable();
            // The summary's entries for this team, with any keys Coilbox added.
            $table->json('build_orders');
            $table->primary(['game_id', 'team']);
            $table->foreign('game_id')->references('game_id')->on('replay')->cascadeOnDelete();
        });

        Schema::create('replay_player', function (Blueprint $table) {
            self::binary($table);
            $table->char('game_id', 32);
            // player, spectator or ai. Players and spectators share the start script's player numbers, AIs have their own.
            $table->string('kind', 9);
            $table->unsignedSmallInteger('number');
            $table->string('name', 255);
            $table->unsignedSmallInteger('team')->nullable();
            $table->unsignedSmallInteger('ally_team')->nullable();
            $table->string('faction', 255)->nullable();
            $table->boolean('won')->nullable();
            // The end of game block's input figures, null for AIs and when the engine took no samples.
            $table->integer('commands')->nullable();
            $table->integer('unit_commands')->nullable();
            $table->integer('mouse_pixels')->nullable();
            $table->integer('mouse_clicks')->nullable();
            $table->integer('key_presses')->nullable();
            $table->primary(['game_id', 'kind', 'number']);
            $table->index('name');
            $table->foreign('game_id')->references('game_id')->on('replay')->cascadeOnDelete();
        });

        Schema::create('replay_chat', function (Blueprint $table) {
            self::binary($table);
            $table->char('game_id', 32);
            // The message's place in the match, from 0.
            $table->unsignedInteger('seq');
            $table->integer('frame');
            $table->unsignedSmallInteger('player');
            $table->string('name', 255);
            // everyone, allies or spectators. Messages to one player are not kept.
            $table->string('audience', 10);
            $table->text('text');
            // For moderators hiding a message (#671).
            $table->dateTime('hidden_at', 6)->nullable();
            $table->char('hidden_by', 36)->nullable();
            $table->primary(['game_id', 'seq']);
            $table->foreign('game_id')->references('game_id')->on('replay')->cascadeOnDelete();
        });

        Schema::create('replay_refusal', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('game_id', 32);
            $table->char('sha256', 64);
            $table->char('uploader', 36);
            // A short code, such as summary_mismatch.
            $table->string('reason', 32);
            // What a moderator reads: the first difference the check found.
            $table->text('detail');
            // The summary as Coilbox sent it.
            $table->longText('summary');
            $table->dateTime('refused_at', 6);
            $table->index('game_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replay_refusal');
        Schema::dropIfExists('replay_chat');
        Schema::dropIfExists('replay_player');
        Schema::dropIfExists('replay_team');
        Schema::table('replay', function (Blueprint $table) {
            $table->dropIndex(['state', 'started_at']);
            $table->dropIndex(['state', 'map']);
            $table->dropIndex(['state', 'game']);
            $table->dropColumn(['game', 'game_version', 'map', 'engine', 'started_at', 'duration', 'winners', 'player_count', 'events', 'extra', 'checked_at']);
        });
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

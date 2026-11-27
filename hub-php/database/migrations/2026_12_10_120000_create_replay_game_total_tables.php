<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What listed replays say about each game version (#670). `replay_game_share`
 * is what one replay added, so taking it out subtracts exactly that.
 * `replay_game_total` is a version's shares added up. A game's own total is
 * its versions added up when the page is read. The share has no foreign key to
 * `replay`, as deleting a replay must not drop a share before it is subtracted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replay_game_share', function (Blueprint $table) {
            self::binary($table);
            $table->char('game_id', 32)->primary();
            $table->string('game', 255);
            // Empty when the replay's game type names no release.
            $table->string('game_version', 255);
            // The replay's counts, as {section: {key: [number, ...]}}.
            $table->longText('figures');
            $table->dateTime('counted_at', 6);
        });

        Schema::create('replay_game_total', function (Blueprint $table) {
            self::binary($table);
            $table->string('game', 255);
            $table->string('game_version', 255);
            $table->unsignedInteger('replays');
            $table->longText('figures');
            $table->primary(['game', 'game_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replay_game_total');
        Schema::dropIfExists('replay_game_share');
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

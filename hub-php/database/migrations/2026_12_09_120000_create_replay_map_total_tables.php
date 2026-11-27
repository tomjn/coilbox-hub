<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What listed replays say about each map version (#669). A map's name holds
 * its version, so totals are kept by map name. `replay_map_share` is what one
 * replay added, so taking it out subtracts exactly that. `replay_map_total` is
 * the shares' heat grids added up. It has no foreign key to `replay`, as
 * deleting a replay must not drop a share before its grids are subtracted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replay_map_share', function (Blueprint $table) {
            self::binary($table);
            $table->char('game_id', 32)->primary();
            $table->string('map', 255)->index();
            // Game seconds.
            $table->unsignedInteger('duration');
            // Each team's start as [x, z, won], won null when nobody won the match.
            $table->json('starts');
            // The heat grids added to the totals, by layer.
            $table->longText('layers');
            $table->dateTime('counted_at', 6);
        });

        Schema::create('replay_map_total', function (Blueprint $table) {
            self::binary($table);
            $table->string('map', 255);
            // orders, deaths or damage.
            $table->string('layer', 8);
            // The map size the cells are counted on. Grids of different sizes cannot be added.
            $table->unsignedInteger('world_width');
            $table->unsignedInteger('world_height');
            // How many replays have anything on this layer.
            $table->unsignedInteger('replays');
            // [[cell, amount], ...] in cell order, on Coilbox's 256 cell grid.
            $table->longText('cells');
            $table->primary(['map', 'layer', 'world_width', 'world_height']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replay_map_total');
        Schema::dropIfExists('replay_map_share');
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

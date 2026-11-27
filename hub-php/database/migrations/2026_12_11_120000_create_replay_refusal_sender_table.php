<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everyone who had sent a replay when it was refused (#694), so each can ask
 * why. `replay_sender` goes with the replay's row. Refusals from before this
 * table are known only by their uploader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replay_refusal_sender', function (Blueprint $table) {
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_bin';
            }
            $table->unsignedBigInteger('refusal_id');
            $table->char('user_id', 36);
            $table->primary(['refusal_id', 'user_id']);
            $table->foreign('refusal_id')->references('id')->on('replay_refusal')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replay_refusal_sender');
    }
};

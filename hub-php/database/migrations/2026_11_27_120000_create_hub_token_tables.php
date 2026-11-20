<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coilbox's tokens once the hub signs it in (#602, part 3c).
 *
 * `personal_access_tokens` is Sanctum's table, with the account id as text
 * because hub accounts keep Supabase's uuids, and `name` holding the sign in
 * session the token belongs to. `auth_refresh_token` keeps each refresh token
 * as a hash, with the one it replaced, so a spent token used again ends its
 * session. `auth_sign_in` holds a Coilbox sign in while the person is at the
 * provider, and then the one-time code Coilbox swaps for tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->char('tokenable_id', 36);
            $table->char('name', 36);
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->index(['tokenable_type', 'tokenable_id']);
            $table->index('name');
        });

        Schema::create('auth_refresh_token', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->char('user_id', 36);
            $table->char('session_id', 36);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('revoked_at', 6)->nullable();
            $table->index('session_id', 'auth_refresh_token_session_idx');
            $table->index('parent_id', 'auth_refresh_token_parent_idx');
            $table->foreign('user_id')->references('id')->on('auth_user')->cascadeOnDelete();
        });

        Schema::create('auth_sign_in', function (Blueprint $table) {
            self::binary($table);
            $table->id();
            $table->char('state_hash', 64)->unique();
            $table->char('code_hash', 64)->nullable()->unique();
            $table->string('provider', 64);
            $table->text('redirect_to');
            $table->string('code_challenge', 128);
            $table->string('code_challenge_method', 8);
            $table->char('user_id', 36)->nullable();
            $table->dateTime('created_at', 6);
            $table->index('created_at', 'auth_sign_in_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_sign_in');
        Schema::dropIfExists('auth_refresh_token');
        Schema::dropIfExists('personal_access_tokens');
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

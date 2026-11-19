<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A copy of Supabase Auth's accounts and their linked sign in providers
 * (#602, part 3a), so the hub can run sign in itself later and still match
 * every account to the rows it owns. Ids are Supabase's own.
 *
 * Supabase stays in charge of sign in until the switch. `php artisan
 * users:copy` copies every account, and each sign in updates that account's
 * copy. Nothing secret is kept: no passwords, tokens or authenticator secrets.
 *
 * `copy_hash` says what was last written, so a sign in that changes nothing
 * writes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_user', function (Blueprint $table) {
            self::binary($table);
            $table->char('id', 36)->primary();
            $table->string('email', 255)->nullable();
            $table->dateTime('email_confirmed_at', 6)->nullable();
            $table->json('app_metadata');
            $table->json('user_metadata');
            $table->boolean('is_anonymous')->default(false);
            $table->dateTime('banned_until', 6)->nullable();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6)->nullable();
            $table->dateTime('last_sign_in_at', 6)->nullable();
            $table->dateTime('copied_at', 6);
            $table->char('copy_hash', 40);
            $table->index('email', 'auth_user_email_idx');
        });

        Schema::create('auth_identity', function (Blueprint $table) {
            self::binary($table);
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->string('provider', 64);
            $table->string('provider_id', 255);
            $table->json('identity_data');
            $table->string('email', 255)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->dateTime('last_sign_in_at', 6)->nullable();
            $table->unique(['provider', 'provider_id'], 'auth_identity_provider_unique');
            $table->index('user_id', 'auth_identity_user_idx');
            $table->foreign('user_id')->references('id')->on('auth_user')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_identity');
        Schema::dropIfExists('auth_user');
    }

    private static function binary(Blueprint $table): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
        }
    }
};

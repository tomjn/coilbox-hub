<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How a moderator ruled on a report, `upheld` or `dismissed`. Open and older handled reports stay null. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report', function (Blueprint $table) {
            $table->string('outcome', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report', function (Blueprint $table) {
            $table->dropColumn('outcome');
        });
    }
};

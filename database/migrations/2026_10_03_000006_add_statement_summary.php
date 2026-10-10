<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            $table->text('summary')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statements', fn (Blueprint $table) => $table->dropColumn('summary'));
    }
};

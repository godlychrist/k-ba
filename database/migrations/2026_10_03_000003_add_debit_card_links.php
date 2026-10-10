<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            $table->string('debit_card_reference', 4)->nullable();
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('instrument_type', 20)->default('unknown');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', fn (Blueprint $table) => $table->dropColumn('instrument_type'));
        Schema::table('bank_statements', fn (Blueprint $table) => $table->dropColumn('debit_card_reference'));
    }
};

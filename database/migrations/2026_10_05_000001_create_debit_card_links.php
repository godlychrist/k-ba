<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debit_card_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('card_reference', 4);
            $table->string('account_reference', 4);
            $table->string('currency', 3);
            $table->timestampsTz();
            $table->unique(['user_id', 'card_reference', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debit_card_links');
    }
};

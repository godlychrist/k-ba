<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_record_id')->constrained()->cascadeOnDelete();
            $table->string('attachment_key', 64);
            $table->string('filename');
            $table->string('path')->nullable();
            $table->string('status', 30);
            $table->string('account_type', 20)->default('unknown');
            $table->string('account_reference', 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('closing_date')->nullable();
            $table->decimal('closing_balance', 18, 2)->nullable();
            $table->boolean('confirmed')->default(false);
            $table->text('extracted_text')->nullable();
            $table->timestampsTz();
            $table->unique(['mail_record_id', 'attachment_key']);
        });
        Schema::create('balance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('account_reference', 4);
            $table->string('currency', 3);
            $table->decimal('amount', 18, 2);
            $table->timestampTz('effective_at');
            $table->timestampsTz();
            $table->index(['user_id', 'account_reference', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_adjustments');
        Schema::dropIfExists('bank_statements');
    }
};

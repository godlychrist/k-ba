<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('email');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestampTz('expires_at');
            $table->text('sync_cursor')->nullable();
            $table->timestampTz('sync_started_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'email']);
        });
        Schema::create('mail_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('message_id');
            $table->string('subject')->nullable();
            $table->timestampTz('received_at');
            $table->string('status', 20);
            $table->timestampsTz();
            $table->unique(['mail_account_id', 'message_id']);
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_record_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);
            $table->string('merchant')->nullable();
            $table->string('account_reference')->nullable();
            $table->timestampTz('occurred_at')->index();
            $table->boolean('date_from_email')->default(true);
            $table->timestampsTz();
        });
        Schema::create('balance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_record_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);
            $table->string('account_reference');
            $table->timestampTz('observed_at')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_snapshots');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('mail_records');
        Schema::dropIfExists('mail_accounts');
    }
};

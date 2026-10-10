<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('mail_record_id')->nullable()->change();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('user_edited')->default(false);
            $table->text('notes')->nullable();
            $table->uuid('client_id')->nullable();
            $table->unique(['user_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['user_edited', 'notes', 'client_id']);
        });
        // Leave the nullable source ID intact: manual movements have no email source.
    }
};

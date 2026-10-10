<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', fn (Blueprint $table) => $table->date('sync_since')->nullable());
    }

    public function down(): void
    {
        Schema::table('mail_accounts', fn (Blueprint $table) => $table->dropColumn('sync_since'));
    }
};

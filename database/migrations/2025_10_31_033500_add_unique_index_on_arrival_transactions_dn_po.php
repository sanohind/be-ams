<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('arrival_transactions', function (Blueprint $table) {
            // Add unique composite index to prevent duplicates
            $table->unique(['dn_number', 'po_number'], 'uniq_arrival_dn_po');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('arrival_transactions', function (Blueprint $table) {
            $table->dropUnique('uniq_arrival_dn_po');
        });
    }
};



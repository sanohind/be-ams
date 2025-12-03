<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Clean up existing data: set visitor_id to NULL where it's 0 (invalid values)
        DB::statement("UPDATE arrival_transactions SET visitor_id = NULL WHERE visitor_id = '0' OR visitor_id = 0");
        
        // Drop existing index first
        Schema::table('arrival_transactions', function (Blueprint $table) {
            $table->dropIndex(['visitor_id']);
        });
        
        // Change visitor_id column type from BIGINT to VARCHAR(255) to match visitor table
        Schema::table('arrival_transactions', function (Blueprint $table) {
            $table->string('visitor_id', 255)->nullable()->change();
        });
        
        // Re-add index after column change
        Schema::table('arrival_transactions', function (Blueprint $table) {
            $table->index('visitor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: Converting back from VARCHAR to BIGINT may lose data if visitor_id contains non-numeric values
        // This is a one-way migration in practice, but we provide rollback for completeness
        
        // Drop existing index first
        Schema::table('arrival_transactions', function (Blueprint $table) {
            $table->dropIndex(['visitor_id']);
        });
        
        // Change back to BIGINT
        Schema::table('arrival_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('visitor_id')->nullable()->change();
        });
        
        // Re-add index
        Schema::table('arrival_transactions', function (Blueprint $table) {
            $table->index('visitor_id');
        });
    }
};

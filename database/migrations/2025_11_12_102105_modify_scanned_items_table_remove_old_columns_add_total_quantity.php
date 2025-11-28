<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Remove columns:
     * - expected_quantity
     * - quantity_variance
     * - match_status
     * 
     * Add column:
     * - total_quantity (from dn_qty in DN Detail)
     */
    public function up(): void
    {
        Schema::table('scanned_items', function (Blueprint $table) {
            // IMPORTANT: Drop generated column first (quantity_variance depends on expected_quantity)
            // MySQL will automatically drop indexes that use match_status when we drop the column
            
            // Drop columns in correct order:
            // 1. Drop generated column first (it depends on expected_quantity)
            $table->dropColumn('quantity_variance');
            
            // 2. Drop match_status enum (this will automatically drop idx_arrival_match index)
            $table->dropColumn('match_status');
            
            // 3. Now we can drop expected_quantity
            $table->dropColumn('expected_quantity');
            
            // 4. Add new column
            $table->integer('total_quantity')->default(0)->after('scanned_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scanned_items', function (Blueprint $table) {
            // Remove new column
            $table->dropColumn('total_quantity');
            
            // Restore old columns in correct order
            $table->integer('expected_quantity')->default(0)->after('scanned_quantity');
            $table->enum('match_status', [
                'matched', 
                'not_found', 
                'quantity_mismatch'
            ])->default('matched')->after('expected_quantity');
            $table->integer('quantity_variance')
                  ->storedAs('scanned_quantity - expected_quantity')
                  ->after('match_status');
            
            // Restore index
            $table->index(['arrival_id', 'match_status'], 'idx_arrival_match');
        });
    }
};

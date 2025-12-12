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
        Schema::table('delivery_performance', function (Blueprint $table) {
            // Tambah kolom untuk Pemenuhan Order (Order Fulfillment)
            $table->integer('total_dn_qty')->default(0)->after('on_time_deliveries');
            $table->integer('total_receipt_qty')->default(0)->after('total_dn_qty');
            $table->decimal('fulfillment_percentage', 5, 2)->default(100.00)->after('total_receipt_qty');
            
            // Tambah kolom untuk Index Calculation
            $table->integer('fulfillment_index')->default(0)->after('fulfillment_percentage');
            $table->integer('delivery_index')->default(0)->after('fulfillment_index');
            
            // Tambah kolom untuk Total Score
            $table->integer('total_index')->default(0)->after('delivery_index');
            $table->integer('final_score')->default(100)->after('total_index');
            
            // Tambah kolom Grade
            $table->enum('performance_grade', ['A', 'B', 'C', 'D'])
                  ->default('A')
                  ->after('final_score')
                  ->index();
            
            // Tambah index untuk query performance
            $table->index('final_score', 'idx_final_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_performance', function (Blueprint $table) {
            // Drop indexes terlebih dahulu
            $table->dropIndex('idx_final_score');
            
            // Drop columns
            $table->dropColumn([
                'total_dn_qty',
                'total_receipt_qty',
                'fulfillment_percentage',
                'fulfillment_index',
                'delivery_index',
                'total_index',
                'final_score',
                'performance_grade'
            ]);
        });
    }
};
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
        Schema::create('delivery_performance', function (Blueprint $table) {
            $table->id();
            
            // Supplier Reference (Logical FK ke scm.business_partner.bp_code)
            $table->string('bp_code', 25)->index();
            
            // Period
            $table->integer('period_month')->index();
            $table->integer('period_year')->index();
            
            // Performance Metrics
            $table->integer('total_delay_days')->default(0);
            $table->integer('total_deliveries')->default(0);
            $table->integer('on_time_deliveries')->default(0);
            
            // Ranking
            $table->integer('ranking')->default(0)->index();
            $table->enum('category', ['best', 'medium', 'worst'])
                  ->default('medium')
                  ->index();
            
            $table->timestamp('calculated_at')->useCurrent();
            $table->timestamps();
            
            // Unique constraint untuk prevent duplicate
            $table->unique(
                ['bp_code', 'period_month', 'period_year'], 
                'unique_supplier_period'
            );
            
            // Composite index untuk ranking queries
            $table->index(['period_year', 'period_month', 'ranking'], 'idx_period_ranking');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_performance');
    }
};

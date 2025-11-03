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
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            
            // Sync Status
            $table->enum('sync_status', ['pending','success','failed','partial'])
                  ->index();
            
            // Metrics
            $table->integer('records_synced')->default(0);
            $table->text('error_message')->nullable();
            
            // Timing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            
            // Index untuk monitoring dan troubleshooting
            $table->index(['sync_status', 'created_at'], 'idx_status_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};

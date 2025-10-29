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
        Schema::create('dn_scan_sessions', function (Blueprint $table) {
            $table->id();
            
            // Foreign Key ke arrival_transactions
            $table->foreignId('arrival_id')
                  ->constrained('arrival_transactions', 'id')
                  ->onDelete('cascade');
            
            $table->string('dn_number', 25)->index();
            
            // Operator Reference (Logical FK ke be_sphere_2.users.id)
            $table->unsignedBigInteger('operator_id')->index();
            
            // Timing
            $table->timestamp('session_start')->useCurrent();
            $table->timestamp('session_end')->nullable();
            $table->integer('session_duration')->nullable();
            
            // Progress
            $table->integer('total_items_scanned')->default(0);
            
            // Status
            $table->enum('status', [
                'in_progress', 
                'completed', 
                'partial', 
                'cancelled'
            ])->default('in_progress')->index();
            
            // Quality Check Status
            $table->enum('label_part_status', ['OK', 'NOT_OK', 'PENDING'])
                  ->default('PENDING')
                  ->index();
            
            $table->enum('coa_msds_status', ['OK', 'NOT_OK', 'PENDING'])
                  ->default('PENDING')
                  ->index();
            
            $table->enum('packing_condition_status', ['OK', 'NOT_OK', 'PENDING'])
                  ->default('PENDING')
                  ->index();
            
            $table->timestamps();
            
            // Index untuk query sessions aktif
            $table->index(['arrival_id', 'status'], 'idx_arrival_session_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dn_scan_sessions');
    }
};

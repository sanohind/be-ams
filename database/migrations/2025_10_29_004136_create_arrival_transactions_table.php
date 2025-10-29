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
        Schema::create('arrival_transactions', function (Blueprint $table) {
            $table->id();
            
            // DN & PO Reference
            $table->string('dn_number', 25)->index();
            $table->string('po_number', 25)->index();
            
            $table->enum('arrival_type', ['regular', 'additional'])
                  ->default('regular')
                  ->index();
            
            $table->date('plan_delivery_date')->nullable()->index();
            $table->time('plan_delivery_time')->nullable()->index();
            
            // SCM Reference Fields
            $table->string('bp_code', 25)->nullable()->index();
            $table->string('driver_name', 255)->nullable();
            $table->string('vehicle_plate', 50)->nullable();
            
            // Link ke arrival_schedule (NULLABLE - diisi belakangan)
            $table->foreignId('schedule_id')
                  ->nullable()
                  ->constrained('arrival_schedule', 'id')
                  ->onDelete('set null');
            
            // Timing - Security Gate
            $table->timestamp('security_checkin_time')->nullable()->index();
            $table->timestamp('security_checkout_time')->nullable();
            $table->integer('security_duration')->default(0);
            
            // Timing - Warehouse
            $table->timestamp('warehouse_checkin_time')->nullable();
            $table->timestamp('warehouse_checkout_time')->nullable();
            $table->integer('warehouse_duration')->default(0);
            
            // Status
            $table->enum('status', [
                'on_time', 
                'delay', 
                'advance', 
                'pending', 
                'cancelled'
            ])->default('pending')->index();
            
            // Cross-Database References
            $table->unsignedBigInteger('pic_receiving')->nullable()->index();
            $table->unsignedBigInteger('visitor_id')->nullable()->index();
            
            $table->timestamps();
            
            // Additional indexes untuk performa
            $table->index('schedule_id');
            $table->index(['bp_code', 'plan_delivery_date'], 'idx_supplier_delivery');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arrival_transactions');
    }
};

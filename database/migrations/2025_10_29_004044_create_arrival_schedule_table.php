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
        Schema::create('arrival_schedule', function (Blueprint $table) {
            $table->id();
            
            // Supplier Reference (Logical FK ke scm.business_partner.bp_code)
            $table->string('bp_code', 25)->index();
            
            // Schedule Pattern
            $table->enum('day_name', [
                'monday', 
                'tuesday', 
                'wednesday', 
                'thursday', 
                'friday', 
                'saturday', 
                'sunday'
            ])->index();
            
            $table->enum('arrival_type', ['regular', 'additional'])
                  ->default('regular')
                  ->index();

            // Schedule Date (untuk additional - one-time schedule)
            // NULL = recurring schedule (regular)
            // FILLED = specific date (additional)
            $table->date('schedule_date')->nullable()->index();
            
            $table->time('arrival_time')->nullable(); 
            $table->time('departure_time')->nullable(); 
            
            // Location & Capacity
            $table->string('dock', 25)->nullable();
            
            // Audit Trail (Logical FK ke be_sphere_2.users.id)
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            
            $table->timestamps();
            
            // Composite index untuk query schedule aktif
            $table->index(['bp_code', 'day_name', 'arrival_type'], 'idx_schedule_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arrival_schedule');
    }
};

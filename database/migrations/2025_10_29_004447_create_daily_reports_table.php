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
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            
            $table->date('report_date')->unique()->index();
            
            // Summary Statistics
            $table->integer('total_arrivals')->default(0);
            $table->integer('total_on_time')->default(0);
            $table->integer('total_delay')->default(0);
            
            // File Reference untuk audit
            $table->string('file_path', 255)->nullable();
            
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};

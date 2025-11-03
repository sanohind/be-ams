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
        Schema::create('scanned_items', function (Blueprint $table) {
            $table->id();
            
            // Foreign Keys (M:M relationship melalui session dan arrival)
            $table->foreignId('session_id')
                  ->constrained('dn_scan_sessions', 'id')
                  ->onDelete('cascade');
                  
            $table->foreignId('arrival_id')
                  ->constrained('arrival_transactions', 'id')
                  ->onDelete('cascade');
            
            $table->string('dn_number', 25)->index();
            
            // Data dari QR Parse
            $table->string('part_no', 50)->index();
            $table->integer('scanned_quantity');
            $table->string('lot_number', 255)->nullable();
            $table->string('customer', 50)->nullable();
            $table->text('qr_raw_data')->nullable();
            
            // Cross-Database: Reference ke SCM (scm.dn_detail.dn_detail_no)
            $table->unsignedBigInteger('dn_detail_no')->nullable()->index();
            
            // Matching Logic
            $table->integer('expected_quantity')->default(0);
            $table->integer('quantity_variance')
                  ->storedAs('scanned_quantity - expected_quantity');
            
            $table->enum('match_status', [
                'matched', 
                'not_found', 
                'quantity_mismatch'
            ])->default('matched')->index();
            
            // Scan Metadata (Logical FK ke be_sphere.users.id)
            $table->unsignedBigInteger('scanned_by')->nullable()->index();
            $table->timestamp('scanned_at')->useCurrent();
            
            $table->timestamps();
            
            // Composite indexes untuk performa
            $table->index(['session_id', 'part_no'], 'idx_session_part');
            $table->index(['arrival_id', 'match_status'], 'idx_arrival_match');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scanned_items');
    }
};

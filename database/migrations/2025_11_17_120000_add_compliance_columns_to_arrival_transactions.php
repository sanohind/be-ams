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
        Schema::table('arrival_transactions', function (Blueprint $table) {
            $table->foreignId('related_arrival_id')
                ->nullable()
                ->after('schedule_id')
                ->constrained('arrival_transactions')
                ->nullOnDelete();

            $table->timestamp('completed_at')
                ->nullable()
                ->after('warehouse_checkout_time');

            $table->string('delivery_compliance', 30)
                ->default('pending')
                ->after('status')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('arrival_transactions', function (Blueprint $table) {
            $table->dropForeign(['related_arrival_id']);
            $table->dropColumn(['related_arrival_id', 'completed_at', 'delivery_compliance']);
        });
    }
};


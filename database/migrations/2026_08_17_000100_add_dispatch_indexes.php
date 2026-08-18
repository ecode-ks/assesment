<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->index('driver_id');
            $table->index('status');
            $table->index('version');
            $table->index('customer_name');
            $table->index('pickup_address');
            $table->index('dropoff_address');
            $table->index(['driver_id', 'status']);
        });

        Schema::table('trip_status_histories', function (Blueprint $table): void {
            $table->index('trip_id');
            $table->index('new_status');
            $table->index(['trip_id', 'created_at']);
        });

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropIndex(['driver_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['version']);
            $table->dropIndex(['customer_name']);
            $table->dropIndex(['pickup_address']);
            $table->dropIndex(['dropoff_address']);
            $table->dropIndex(['driver_id', 'status']);
        });

        Schema::table('trip_status_histories', function (Blueprint $table): void {
            $table->dropIndex(['trip_id']);
            $table->dropIndex(['new_status']);
            $table->dropIndex(['trip_id', 'created_at']);
        });

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex(['entity_type', 'entity_id']);
            $table->dropIndex(['created_at']);
        });
    }
};

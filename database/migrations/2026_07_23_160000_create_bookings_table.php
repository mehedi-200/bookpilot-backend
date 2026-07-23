<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->char('reference', 12)->unique(); // BP-2026-0001
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('status', ['pending', 'confirmed', 'completed', 'cancelled'])
                ->default('pending')
                ->index();
            $table->enum('source', ['widget', 'manual'])->default('manual')->index();
            // Set by the AI agent in Feature 6; FK added with the conversations table.
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->string('notes', 500)->nullable();
            $table->dateTime('rescheduled_from')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 200)->nullable();
            // Filled by the GarageFlow sync in Feature 8.
            $table->string('garageflow_job_id', 40)->nullable();
            $table->enum('sync_status', ['pending', 'synced', 'failed'])->nullable();
            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            // Availability lookups and the calendar both query this pair.
            $table->index(['starts_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};

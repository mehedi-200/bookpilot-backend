<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->char('token', 40)->unique(); // widget resume token
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // Captured in chat before we know which customer they are.
            $table->string('guest_name', 120)->nullable();
            $table->string('guest_phone', 30)->nullable();
            $table->enum('status', ['active', 'ended', 'handed_off'])->default('active')->index();
            $table->string('handoff_reason', 200)->nullable();
            $table->dateTime('last_activity_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant', 'tool']);
            $table->text('content')->nullable();
            // Assistant turn: the tool_use blocks it emitted, [{id,name,input}].
            $table->json('tool_calls')->nullable();
            // Tool turn: which call this answers, and what came back.
            $table->string('tool_use_id', 64)->nullable();
            $table->string('tool_name', 60)->nullable();
            $table->json('tool_result')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
        });
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};

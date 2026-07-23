<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->unique(); // 'garageflow'
            $table->string('base_url', 255)->nullable();
            $table->text('api_token')->nullable(); // encrypted cast
            // GarageFlow requires a mechanic on every service job.
            $table->unsignedBigInteger('default_mechanic_id')->nullable();
            $table->string('default_mechanic_name', 120)->nullable();
            $table->boolean('enabled')->default(false);
            $table->dateTime('last_ok_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};

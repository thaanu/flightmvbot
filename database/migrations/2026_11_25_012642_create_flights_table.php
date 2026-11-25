<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            // Natural key: type|FLIGHTNO|Y-m-d|HH:MM (the source "id" is not stable).
            $table->string('flight_key')->unique();
            $table->string('type', 16); // arrival | departure
            $table->string('flight_number', 16); // "EY 378"
            $table->string('flight_number_compact', 16); // "EY378"
            $table->string('airline')->nullable();
            $table->string('airline_code', 8)->nullable();
            $table->string('origin')->nullable();
            $table->string('origin_code', 8)->nullable();
            $table->string('destination')->nullable();
            $table->string('destination_code', 8)->nullable();
            $table->dateTime('scheduled_at'); // Maldives local time
            $table->dateTime('estimated_at')->nullable();
            $table->string('terminal', 8)->nullable();
            $table->string('gate', 16)->nullable();
            $table->string('status', 32);
            $table->string('category', 16)->nullable(); // international | domestic
            $table->integer('delay_minutes')->nullable();
            $table->integer('early_minutes')->nullable();
            $table->json('via_routes')->nullable();
            $table->json('codeshares')->nullable();
            $table->json('raw')->nullable();
            $table->dateTime('last_seen_at');
            $table->timestamps();

            $table->index(['flight_number_compact', 'scheduled_at']);
            $table->index(['type', 'scheduled_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flights');
    }
};

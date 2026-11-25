<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained()->cascadeOnDelete();
            $table->string('old_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->dateTime('old_estimated_at')->nullable();
            $table->dateTime('new_estimated_at')->nullable();
            $table->dateTime('changed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_status_changes');
    }
};

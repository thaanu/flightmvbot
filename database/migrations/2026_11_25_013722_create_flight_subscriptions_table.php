<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id');
            $table->foreignId('flight_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['chat_id', 'flight_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_subscriptions');
    }
};

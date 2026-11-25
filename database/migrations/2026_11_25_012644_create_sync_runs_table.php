<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at')->index();
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('flights_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('changes_count')->default(0);
            $table->string('source_last_updated', 16)->nullable();
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};

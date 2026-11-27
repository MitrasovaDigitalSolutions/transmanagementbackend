<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->date('schedule_date');
            $table->string('status', 20)->default('ready');
            $table->time('departure_time')->nullable();
            $table->string('destination', 150)->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('crews')->restrictOnDelete();
            $table->foreignId('kondektur_id')->nullable()->constrained('crews')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_id', 'schedule_date']);
            $table->index(['schedule_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};

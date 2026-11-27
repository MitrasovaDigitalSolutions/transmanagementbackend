<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crews', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 20)->index();
            $table->string('nik', 16)->nullable()->unique();
            $table->string('phone', 30)->nullable();
            $table->string('sim_number', 30)->nullable();
            $table->string('sim_type', 10)->nullable();
            $table->date('sim_expires_at')->nullable();
            $table->text('address')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crews');
    }
};

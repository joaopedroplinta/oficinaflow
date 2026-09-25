<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('category');
            $table->string('brand');
            $table->string('model');
            $table->string('serial_number')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('work_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_events');
    }
};

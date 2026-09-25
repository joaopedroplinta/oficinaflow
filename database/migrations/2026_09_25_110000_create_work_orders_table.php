<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            // Identity do Postgres: número sequencial único, sem corrida entre aberturas simultâneas.
            $table->bigInteger('number')->generatedAs()->always()->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('status');

            // Snapshot do equipamento na entrada: editar o cadastro não muda o histórico.
            $table->string('device_category');
            $table->string('device_brand');
            $table->string('device_model');
            $table->string('device_serial_number')->nullable();

            $table->text('reported_problem');
            $table->text('intake_condition');
            $table->text('accessories');
            $table->text('diagnosis')->nullable();
            $table->foreignId('assigned_technician_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->timestampTz('received_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->index('status');
            $table->index('customer_id');
            $table->index('device_id');
        });

        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_status_check CHECK (status IN ('received', 'diagnosing', 'awaiting_approval', 'approved', 'in_progress', 'ready_for_pickup', 'delivered', 'awaiting_return', 'canceled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};

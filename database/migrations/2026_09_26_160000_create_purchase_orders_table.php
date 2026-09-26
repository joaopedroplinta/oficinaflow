<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            // Identity do Postgres: número sequencial único, sem corrida entre criações simultâneas.
            $table->bigInteger('number')->generatedAs()->always()->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('canceled_at')->nullable();
            $table->foreignId('canceled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->index('status');
            $table->index('supplier_id');
        });

        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('open', 'partially_received', 'received', 'canceled'))");
        // Cancelar sem dizer quando, quem e por quê não vale: os dados acompanham o estado.
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_cancellation_check CHECK (
            (status = 'canceled') = (canceled_at IS NOT NULL AND canceled_by IS NOT NULL AND cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> '')
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};

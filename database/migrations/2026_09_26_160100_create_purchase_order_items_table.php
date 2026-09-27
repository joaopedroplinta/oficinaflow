<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->integer('quantity');
            $table->bigInteger('unit_cost_cents');

            // Mesma peça não repete no pedido.
            $table->unique(['purchase_order_id', 'part_id']);
            $table->index('part_id');
        });

        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_quantity_check CHECK (quantity > 0 AND quantity <= 1000000)');
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_cost_check CHECK (unit_cost_cents >= 0 AND unit_cost_cents <= 100000000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->bigInteger('sale_price_cents');
            $table->integer('on_hand')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE parts ADD CONSTRAINT parts_stock_check CHECK (on_hand >= 0 AND reserved_quantity >= 0 AND reserved_quantity <= on_hand)');
        DB::statement('ALTER TABLE parts ADD CONSTRAINT parts_price_check CHECK (sale_price_cents >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('parts');
    }
};

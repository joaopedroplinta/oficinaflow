<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->foreignId('part_id')->nullable()->constrained()->restrictOnDelete();
            // Snapshot: descrição e preço não acompanham o catálogo depois de gravados.
            $table->string('description');
            $table->integer('quantity');
            $table->bigInteger('unit_price_cents');
            $table->integer('position');

            $table->index('quote_id');
        });

        DB::statement("ALTER TABLE quote_items ADD CONSTRAINT quote_items_kind_check CHECK (kind IN ('part', 'service'))");
        DB::statement('ALTER TABLE quote_items ADD CONSTRAINT quote_items_values_check CHECK (quantity > 0 AND unit_price_cents >= 0)');
        DB::statement("ALTER TABLE quote_items ADD CONSTRAINT quote_items_part_check CHECK ((kind = 'part' AND part_id IS NOT NULL) OR (kind = 'service' AND part_id IS NULL))");
        DB::statement('CREATE UNIQUE INDEX quote_items_part_once_per_quote ON quote_items (quote_id, part_id) WHERE part_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
    }
};

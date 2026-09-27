<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->bigInteger('unit_cost_cents')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('supplier_id');
        });

        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_cost_check CHECK (unit_cost_cents IS NULL OR (unit_cost_cents >= 0 AND unit_cost_cents <= 100000000))');
        // Custo e fornecedor só existem na entrada; consumo e estorno não os carregam.
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_receipt_only_check CHECK (kind = 'receipt' OR (unit_cost_cents IS NULL AND supplier_id IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_receipt_only_check');
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_cost_check');

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('unit_cost_cents');
        });
    }
};

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
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('purchase_order_item_id');
        });

        // O vínculo com o pedido só existe na entrada e sempre com custo e fornecedor (os do pedido).
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_receipt_only_check');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_receipt_only_check CHECK (kind = 'receipt' OR (unit_cost_cents IS NULL AND supplier_id IS NULL AND purchase_order_item_id IS NULL))");
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_purchase_check CHECK (purchase_order_item_id IS NULL OR (unit_cost_cents IS NOT NULL AND supplier_id IS NOT NULL))');

        // Recebido nunca passa do pedido, da mesma peça e do fornecedor do pedido. As Actions travam o pedido antes de
        // gravar (o que serializa esta soma); o trigger é a rede contra SQL direto ou bug.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION inventory_movements_purchase_guard() RETURNS trigger AS $$
            DECLARE
                item purchase_order_items%ROWTYPE;
                order_supplier bigint;
                received integer;
            BEGIN
                IF NEW.purchase_order_item_id IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT * INTO item FROM purchase_order_items WHERE id = NEW.purchase_order_item_id;
                SELECT supplier_id INTO order_supplier FROM purchase_orders WHERE id = item.purchase_order_id;
                IF item.part_id IS DISTINCT FROM NEW.part_id OR order_supplier IS DISTINCT FROM NEW.supplier_id THEN
                    RAISE EXCEPTION 'a purchase receipt must match the part and the supplier of its order item' USING ERRCODE = 'check_violation';
                END IF;
                SELECT COALESCE(SUM(quantity), 0) INTO received FROM inventory_movements WHERE purchase_order_item_id = item.id;
                IF received + NEW.quantity > item.quantity THEN
                    RAISE EXCEPTION 'receipts of order item % would total % of %', item.id, received + NEW.quantity, item.quantity USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER inventory_movements_purchase BEFORE INSERT ON inventory_movements FOR EACH ROW EXECUTE FUNCTION inventory_movements_purchase_guard()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_movements_purchase ON inventory_movements');
        DB::unprepared('DROP FUNCTION IF EXISTS inventory_movements_purchase_guard()');
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_purchase_check');
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_receipt_only_check');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_receipt_only_check CHECK (kind = 'receipt' OR (unit_cost_cents IS NULL AND supplier_id IS NULL))");

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_item_id');
        });
    }
};

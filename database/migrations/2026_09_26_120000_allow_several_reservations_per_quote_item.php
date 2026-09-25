<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reserva parcial: um item pode ter várias reservas; a soma delas é limitada por trigger (abaixo), não por UNIQUE.
        Schema::table('part_reservations', function (Blueprint $table) {
            $table->dropUnique(['quote_item_id']);
            $table->index('quote_item_id');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION part_reservations_match_item_guard() RETURNS trigger AS $$
            DECLARE
                item quote_items%ROWTYPE;
                quote_status text;
                already integer;
            BEGIN
                SELECT * INTO item FROM quote_items WHERE id = NEW.quote_item_id;
                IF item.kind IS DISTINCT FROM 'part' THEN
                    RAISE EXCEPTION 'quote item % is not a part item; it cannot be reserved', NEW.quote_item_id USING ERRCODE = 'check_violation';
                END IF;
                IF item.part_id IS DISTINCT FROM NEW.part_id THEN
                    RAISE EXCEPTION 'reservation must reserve the part (%) of quote item %', item.part_id, item.id USING ERRCODE = 'check_violation';
                END IF;
                SELECT status INTO quote_status FROM quotes WHERE id = item.quote_id;
                IF quote_status IS DISTINCT FROM 'issued' THEN
                    RAISE EXCEPTION 'quote item % belongs to a % quote; only issued quotes can have reservations', item.id, quote_status USING ERRCODE = 'check_violation';
                END IF;
                -- Reservas ativas + consumidas nunca passam da quantidade do item. As Actions travam a ordem antes de
                -- gravar (protocolo de locks), o que serializa este cálculo; a liberada não conta.
                SELECT COALESCE(SUM(quantity), 0) INTO already FROM part_reservations
                    WHERE quote_item_id = NEW.quote_item_id AND status IN ('active', 'consumed');
                IF NEW.status <> 'released' AND already + NEW.quantity > item.quantity THEN
                    RAISE EXCEPTION 'reservations of quote item % would total % of %', item.id, already + NEW.quantity, item.quantity USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        // Volta ao 1:1. Exige que nenhum item tenha mais de uma reserva (senão o UNIQUE falha e a migration para);
        // reservas parciais únicas (menores que o item) continuam no banco, mas novos inserts voltam a exigir a quantidade inteira.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION part_reservations_match_item_guard() RETURNS trigger AS $$
            DECLARE
                item quote_items%ROWTYPE;
                quote_status text;
            BEGIN
                SELECT * INTO item FROM quote_items WHERE id = NEW.quote_item_id;
                IF item.kind IS DISTINCT FROM 'part' THEN
                    RAISE EXCEPTION 'quote item % is not a part item; it cannot be reserved', NEW.quote_item_id USING ERRCODE = 'check_violation';
                END IF;
                IF item.part_id IS DISTINCT FROM NEW.part_id OR item.quantity IS DISTINCT FROM NEW.quantity THEN
                    RAISE EXCEPTION 'reservation must reserve exactly the part (%) and quantity (%) of quote item %', item.part_id, item.quantity, item.id USING ERRCODE = 'check_violation';
                END IF;
                SELECT status INTO quote_status FROM quotes WHERE id = item.quote_id;
                IF quote_status IS DISTINCT FROM 'issued' THEN
                    RAISE EXCEPTION 'quote item % belongs to a % quote; only issued quotes can have reservations', item.id, quote_status USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        Schema::table('part_reservations', function (Blueprint $table) {
            $table->dropIndex(['quote_item_id']);
            $table->unique('quote_item_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A reserva só existe para um item de peça de uma versão emitida, com a mesma peça e a mesma quantidade dele.
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
        DB::unprepared('CREATE TRIGGER part_reservations_match_item BEFORE INSERT ON part_reservations FOR EACH ROW EXECUTE FUNCTION part_reservations_match_item_guard()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS part_reservations_match_item ON part_reservations');
        DB::unprepared('DROP FUNCTION IF EXISTS part_reservations_match_item_guard()');
    }
};

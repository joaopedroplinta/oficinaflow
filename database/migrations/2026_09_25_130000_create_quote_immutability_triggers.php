<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A versão emitida é imutável no banco, não só na Action: itens, total e observações congelam
// quando ela sai de rascunho, e o status só anda para frente (draft → issued → superseded).
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION quote_items_frozen_guard() RETURNS trigger AS $$
            DECLARE
                target_quote bigint := COALESCE(NEW.quote_id, OLD.quote_id);
                current_status text;
            BEGIN
                SELECT status INTO current_status FROM quotes WHERE id = target_quote;
                IF current_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'quote % is not a draft; its items are frozen', target_quote USING ERRCODE = 'check_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER quote_items_frozen BEFORE INSERT OR UPDATE OR DELETE ON quote_items FOR EACH ROW EXECUTE FUNCTION quote_items_frozen_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION quotes_frozen_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status <> 'draft' AND (
                    NEW.notes IS DISTINCT FROM OLD.notes
                    OR NEW.total_cents <> OLD.total_cents
                    OR NEW.version <> OLD.version
                    OR NEW.work_order_id <> OLD.work_order_id
                    OR NEW.created_by <> OLD.created_by
                ) THEN
                    RAISE EXCEPTION 'quote % is not a draft; it cannot change', OLD.id USING ERRCODE = 'check_violation';
                END IF;
                IF (OLD.status = 'issued' AND NEW.status = 'draft') OR (OLD.status = 'superseded' AND NEW.status <> 'superseded') THEN
                    RAISE EXCEPTION 'quote % cannot go back from % to %', OLD.id, OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER quotes_frozen BEFORE UPDATE ON quotes FOR EACH ROW EXECUTE FUNCTION quotes_frozen_guard()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS quotes_frozen ON quotes');
        DB::unprepared('DROP FUNCTION IF EXISTS quotes_frozen_guard()');
        DB::unprepared('DROP TRIGGER IF EXISTS quote_items_frozen ON quote_items');
        DB::unprepared('DROP FUNCTION IF EXISTS quote_items_frozen_guard()');
    }
};

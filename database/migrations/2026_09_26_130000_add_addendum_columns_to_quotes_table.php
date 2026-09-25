<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('kind')->default('version');
            $table->unsignedBigInteger('base_quote_id')->nullable();

            // Versões e adendos têm numeração própria (versão 1, 2… e adendo 1, 2…).
            $table->dropUnique(['work_order_id', 'version']);
            $table->unique(['work_order_id', 'kind', 'version']);
            $table->index('base_quote_id');
        });

        DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_kind_check CHECK (kind IN ('version', 'addendum'))");
        // Só o adendo tem versão base, e todo adendo tem; adendo nunca é substituído (não há revisão de adendo).
        DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_base_check CHECK ((kind = 'addendum') = (base_quote_id IS NOT NULL) AND (kind = 'version' OR status <> 'superseded'))");
        // A base é um orçamento da própria ordem (FK composta sobre o UNIQUE (work_order_id, id) que já existe).
        DB::statement('ALTER TABLE quotes ADD CONSTRAINT quotes_base_fk FOREIGN KEY (work_order_id, base_quote_id) REFERENCES quotes (work_order_id, id) ON DELETE RESTRICT');

        // "No máximo uma versão não substituída por ordem" vale para versões; adendos convivem com a versão atual.
        DB::statement('DROP INDEX quotes_one_current_per_order');
        DB::statement("CREATE UNIQUE INDEX quotes_one_current_per_order ON quotes (work_order_id) WHERE status <> 'superseded' AND kind = 'version'");

        // Tipo e base não mudam nunca (o guarda antigo só protegia depois de emitido).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION quotes_frozen_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.kind <> OLD.kind OR NEW.base_quote_id IS DISTINCT FROM OLD.base_quote_id THEN
                    RAISE EXCEPTION 'quote % keeps its kind and base', OLD.id USING ERRCODE = 'check_violation';
                END IF;
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

        // Adendo só nasce com o reparo em andamento, sobre a versão atual aprovada.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION quotes_addendum_guard() RETURNS trigger AS $$
            DECLARE
                base quotes%ROWTYPE;
                order_status text;
                order_current bigint;
            BEGIN
                IF NEW.kind <> 'addendum' THEN
                    RETURN NEW;
                END IF;
                SELECT * INTO base FROM quotes WHERE id = NEW.base_quote_id;
                IF base.kind IS DISTINCT FROM 'version' OR base.status IS DISTINCT FROM 'issued' THEN
                    RAISE EXCEPTION 'addendum base % must be an issued version', NEW.base_quote_id USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM quote_decisions WHERE quote_id = base.id AND decision = 'approved') THEN
                    RAISE EXCEPTION 'addendum base % is not approved', base.id USING ERRCODE = 'check_violation';
                END IF;
                SELECT status, current_quote_id INTO order_status, order_current FROM work_orders WHERE id = NEW.work_order_id;
                IF order_status IS DISTINCT FROM 'in_progress' OR order_current IS DISTINCT FROM base.id THEN
                    RAISE EXCEPTION 'addenda only exist while the repair is in progress, over the current version' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER quotes_addendum BEFORE INSERT ON quotes FOR EACH ROW EXECUTE FUNCTION quotes_addendum_guard()');

        // Item de adendo só é reservado depois que o adendo foi aprovado.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION part_reservations_match_item_guard() RETURNS trigger AS $$
            DECLARE
                item quote_items%ROWTYPE;
                quote_status text;
                quote_kind text;
                already integer;
            BEGIN
                SELECT * INTO item FROM quote_items WHERE id = NEW.quote_item_id;
                IF item.kind IS DISTINCT FROM 'part' THEN
                    RAISE EXCEPTION 'quote item % is not a part item; it cannot be reserved', NEW.quote_item_id USING ERRCODE = 'check_violation';
                END IF;
                IF item.part_id IS DISTINCT FROM NEW.part_id THEN
                    RAISE EXCEPTION 'reservation must reserve the part (%) of quote item %', item.part_id, item.id USING ERRCODE = 'check_violation';
                END IF;
                SELECT status, kind INTO quote_status, quote_kind FROM quotes WHERE id = item.quote_id;
                IF quote_status IS DISTINCT FROM 'issued' THEN
                    RAISE EXCEPTION 'quote item % belongs to a % quote; only issued quotes can have reservations', item.id, quote_status USING ERRCODE = 'check_violation';
                END IF;
                IF quote_kind = 'addendum' AND NOT EXISTS (SELECT 1 FROM quote_decisions WHERE quote_id = item.quote_id AND decision = 'approved') THEN
                    RAISE EXCEPTION 'quote item % belongs to an addendum that is not approved', item.id USING ERRCODE = 'check_violation';
                END IF;
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
                SELECT COALESCE(SUM(quantity), 0) INTO already FROM part_reservations
                    WHERE quote_item_id = NEW.quote_item_id AND status IN ('active', 'consumed');
                IF NEW.status <> 'released' AND already + NEW.quantity > item.quantity THEN
                    RAISE EXCEPTION 'reservations of quote item % would total % of %', item.id, already + NEW.quantity, item.quantity USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS quotes_addendum ON quotes');
        DB::unprepared('DROP FUNCTION IF EXISTS quotes_addendum_guard()');
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

        // Sem adendos não há mais o que guardar: a migration recusa voltar se ainda existir algum.
        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM quotes WHERE kind = 'addendum') THEN RAISE EXCEPTION 'addenda exist; remove them before rolling back'; END IF; END \$\$");
        DB::statement('DROP INDEX quotes_one_current_per_order');
        DB::statement("CREATE UNIQUE INDEX quotes_one_current_per_order ON quotes (work_order_id) WHERE status <> 'superseded'");
        DB::statement('ALTER TABLE quotes DROP CONSTRAINT quotes_base_fk');
        DB::statement('ALTER TABLE quotes DROP CONSTRAINT quotes_base_check');
        DB::statement('ALTER TABLE quotes DROP CONSTRAINT quotes_kind_check');

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex(['base_quote_id']);
            $table->dropUnique(['work_order_id', 'kind', 'version']);
            $table->unique(['work_order_id', 'version']);
            $table->dropColumn(['kind', 'base_quote_id']);
        });
    }
};

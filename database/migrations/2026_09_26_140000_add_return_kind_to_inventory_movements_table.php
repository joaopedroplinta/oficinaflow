<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Estorno: o consumo nunca é apagado; a peça que volta à prateleira é um movimento novo que aponta para ele.
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('reverses_movement_id')->nullable();
            $table->index('reverses_movement_id');
        });

        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_kind_check');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_kind_check CHECK (kind IN ('receipt', 'consumption', 'return'))");
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_reservation_check');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_reservation_check CHECK (
            (kind = 'receipt' AND reservation_id IS NULL AND reverses_movement_id IS NULL)
            OR (kind = 'consumption' AND reservation_id IS NOT NULL AND reverses_movement_id IS NULL)
            OR (kind = 'return' AND reservation_id IS NULL AND reverses_movement_id IS NOT NULL)
        )");
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_reverses_fk FOREIGN KEY (reverses_movement_id) REFERENCES inventory_movements (id) ON DELETE RESTRICT');

        // Só se estorna consumo, da mesma peça, e a soma dos estornos de um consumo não passa do que foi consumido.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION inventory_movements_return_guard() RETURNS trigger AS $$
            DECLARE
                target inventory_movements%ROWTYPE;
                returned integer;
            BEGIN
                IF NEW.kind <> 'return' THEN
                    RETURN NEW;
                END IF;
                SELECT * INTO target FROM inventory_movements WHERE id = NEW.reverses_movement_id;
                IF target.kind IS DISTINCT FROM 'consumption' OR target.part_id IS DISTINCT FROM NEW.part_id THEN
                    RAISE EXCEPTION 'a return must reverse a consumption of the same part' USING ERRCODE = 'check_violation';
                END IF;
                SELECT COALESCE(SUM(quantity), 0) INTO returned FROM inventory_movements WHERE reverses_movement_id = target.id;
                IF returned + NEW.quantity > target.quantity THEN
                    RAISE EXCEPTION 'returns of consumption % would total % of %', target.id, returned + NEW.quantity, target.quantity USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER inventory_movements_return BEFORE INSERT ON inventory_movements FOR EACH ROW EXECUTE FUNCTION inventory_movements_return_guard()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_movements_return ON inventory_movements');
        DB::unprepared('DROP FUNCTION IF EXISTS inventory_movements_return_guard()');
        // Falha se já houver estorno no livro-razão (o histórico não se apaga).
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_reverses_fk');
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_reservation_check');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_reservation_check CHECK ((kind = 'receipt' AND reservation_id IS NULL) OR (kind = 'consumption' AND reservation_id IS NOT NULL))");
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_kind_check');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_kind_check CHECK (kind IN ('receipt', 'consumption'))");

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex(['reverses_movement_id']);
            $table->dropColumn('reverses_movement_id');
        });
    }
};

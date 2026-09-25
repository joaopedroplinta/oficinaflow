<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->integer('quantity');
            $table->string('status');
            $table->foreignId('reserved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('reserved_at');
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();

            $table->index('part_id');
        });

        DB::statement('ALTER TABLE part_reservations ADD CONSTRAINT part_reservations_quantity_check CHECK (quantity > 0)');
        DB::statement("ALTER TABLE part_reservations ADD CONSTRAINT part_reservations_status_check CHECK (status IN ('active', 'released', 'consumed'))");
        // O status e as datas contam a mesma história.
        DB::statement("ALTER TABLE part_reservations ADD CONSTRAINT part_reservations_dates_check CHECK (
            (status = 'active' AND released_at IS NULL AND consumed_at IS NULL)
            OR (status = 'released' AND released_at IS NOT NULL AND consumed_at IS NULL)
            OR (status = 'consumed' AND consumed_at IS NOT NULL AND released_at IS NULL)
        )");

        // Reserva ativa só sai de `active` (liberada ou consumida); depois disso é histórico, e nunca é apagada.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION part_reservations_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'part reservations are history and cannot be deleted' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status <> 'active' THEN
                    RAISE EXCEPTION 'reservation % is already % and cannot change', OLD.id, OLD.status USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.quote_item_id <> OLD.quote_item_id OR NEW.part_id <> OLD.part_id OR NEW.quantity <> OLD.quantity
                    OR NEW.reserved_by <> OLD.reserved_by OR NEW.reserved_at <> OLD.reserved_at THEN
                    RAISE EXCEPTION 'reservation % keeps its item, part and quantity', OLD.id USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER part_reservations_guard BEFORE UPDATE OR DELETE ON part_reservations FOR EACH ROW EXECUTE FUNCTION part_reservations_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('part_reservations');
        DB::unprepared('DROP FUNCTION IF EXISTS part_reservations_guard()');
    }
};

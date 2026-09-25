<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->integer('quantity');
            // A FK para part_reservations entra com a tabela de reservas; UNIQUE: um consumo por reserva.
            $table->unsignedBigInteger('reservation_id')->nullable()->unique();
            $table->string('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('part_id');
        });

        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_kind_check CHECK (kind IN ('receipt', 'consumption'))");
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_quantity_check CHECK (quantity > 0)');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_reservation_check CHECK ((kind = 'receipt' AND reservation_id IS NULL) OR (kind = 'consumption' AND reservation_id IS NOT NULL))");

        // Livro-razão: o histórico físico do estoque não se reescreve.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION inventory_movements_append_only_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'inventory movements are append-only' USING ERRCODE = 'check_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER inventory_movements_append_only BEFORE UPDATE OR DELETE ON inventory_movements FOR EACH ROW EXECUTE FUNCTION inventory_movements_append_only_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        DB::unprepared('DROP FUNCTION IF EXISTS inventory_movements_append_only_guard()');
    }
};

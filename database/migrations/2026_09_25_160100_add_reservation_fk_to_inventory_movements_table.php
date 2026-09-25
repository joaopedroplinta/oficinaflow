<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // RESTRICT: SET NULL/CASCADE colidiriam com o livro-razão append-only e o CHECK tipo↔reserva.
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_reservation_fk FOREIGN KEY (reservation_id) REFERENCES part_reservations (id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_reservation_fk');
    }
};

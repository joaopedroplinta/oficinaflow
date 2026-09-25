<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Cancelar sem dizer por quê não vale: o motivo acompanha o estado.
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_cancellation_check CHECK (
            status NOT IN ('awaiting_return', 'canceled') OR (cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> '')
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT work_orders_cancellation_check');
    }
};

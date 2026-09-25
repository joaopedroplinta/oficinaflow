<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A linha do tempo não anda para trás: recebida ≤ iniciada ≤ concluída ≤ entregue (quando preenchidas).
        DB::statement('ALTER TABLE work_orders ADD CONSTRAINT work_orders_dates_order_check CHECK (
            (started_at IS NULL OR started_at >= received_at)
            AND (ready_at IS NULL OR started_at IS NULL OR ready_at >= started_at)
            AND (delivered_at IS NULL OR ready_at IS NULL OR delivered_at >= ready_at)
        )');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT work_orders_dates_order_check');
    }
};

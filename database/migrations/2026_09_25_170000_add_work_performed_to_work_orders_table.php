<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE work_orders ADD COLUMN work_performed text NULL');

        // Estados de execução e as datas/descrição que os justificam contam a mesma história.
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_execution_check CHECK (
            (status NOT IN ('in_progress', 'ready_for_pickup', 'delivered') OR started_at IS NOT NULL)
            AND (status NOT IN ('ready_for_pickup', 'delivered') OR (ready_at IS NOT NULL AND work_performed IS NOT NULL AND btrim(work_performed) <> ''))
            AND (status <> 'delivered' OR delivered_at IS NOT NULL)
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT work_orders_execution_check');
        DB::statement('ALTER TABLE work_orders DROP COLUMN work_performed');
    }
};

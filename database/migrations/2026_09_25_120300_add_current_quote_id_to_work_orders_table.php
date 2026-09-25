<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE work_orders ADD COLUMN current_quote_id bigint NULL');
        // FK composta: o orçamento apontado precisa pertencer à própria ordem.
        DB::statement('ALTER TABLE work_orders ADD CONSTRAINT work_orders_current_quote_fk FOREIGN KEY (id, current_quote_id) REFERENCES quotes (work_order_id, id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT work_orders_current_quote_fk');
        DB::statement('ALTER TABLE work_orders DROP COLUMN current_quote_id');
    }
};

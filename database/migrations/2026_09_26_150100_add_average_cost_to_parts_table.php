<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Custo médio ponderado móvel, em centavos inteiros; NULL até a primeira entrada com custo.
        DB::statement('ALTER TABLE parts ADD COLUMN average_cost_cents bigint NULL');
        DB::statement('ALTER TABLE parts ADD CONSTRAINT parts_average_cost_check CHECK (average_cost_cents IS NULL OR average_cost_cents >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE parts DROP CONSTRAINT parts_average_cost_check');
        DB::statement('ALTER TABLE parts DROP COLUMN average_cost_cents');
    }
};

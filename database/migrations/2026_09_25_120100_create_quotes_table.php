<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status');
            $table->text('notes')->nullable();
            $table->bigInteger('total_cents')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampsTz();

            $table->unique(['work_order_id', 'version']);
            // Alvo da FK composta de work_orders.current_quote_id: garante orçamento da própria ordem.
            $table->unique(['work_order_id', 'id']);
        });

        DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_status_check CHECK (status IN ('draft', 'issued', 'superseded'))");
        DB::statement('ALTER TABLE quotes ADD CONSTRAINT quotes_total_check CHECK (total_cents >= 0 AND version > 0)');
        // No máximo uma versão não substituída por ordem.
        DB::statement("CREATE UNIQUE INDEX quotes_one_current_per_order ON quotes (work_order_id) WHERE status <> 'superseded'");
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};

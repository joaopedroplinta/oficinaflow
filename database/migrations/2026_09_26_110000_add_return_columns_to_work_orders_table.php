<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('kind')->default('regular');
            $table->unsignedBigInteger('origin_order_id')->nullable();
            $table->timestampTz('warranty_until')->nullable();

            $table->index('kind');
            $table->index('origin_order_id');
        });

        // Ordens já entregues ganham o prazo padrão a partir da entrega.
        DB::statement("UPDATE work_orders SET warranty_until = delivered_at + interval '90 days' WHERE status = 'delivered'");

        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_kind_check CHECK (kind IN ('regular', 'warranty', 'rework'))");
        // Só a ordem de retorno tem origem, e toda ordem de retorno tem.
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_origin_check CHECK ((kind = 'regular') = (origin_order_id IS NULL))");
        // O prazo da garantia nasce com a entrega e não existe antes dela.
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_warranty_check CHECK ((status = 'delivered') = (warranty_until IS NOT NULL) AND (warranty_until IS NULL OR delivered_at IS NULL OR warranty_until >= delivered_at))");

        // O retorno é do mesmo cliente e do mesmo equipamento da ordem de origem (FK composta).
        DB::statement('CREATE UNIQUE INDEX work_orders_id_customer_device_unique ON work_orders (id, customer_id, device_id)');
        DB::statement('ALTER TABLE work_orders ADD CONSTRAINT work_orders_origin_fk FOREIGN KEY (origin_order_id, customer_id, device_id) REFERENCES work_orders (id, customer_id, device_id) ON DELETE RESTRICT');

        // No máximo uma ordem de retorno aberta por original.
        DB::statement("CREATE UNIQUE INDEX work_orders_one_open_return_per_origin ON work_orders (origin_order_id) WHERE origin_order_id IS NOT NULL AND status NOT IN ('delivered', 'canceled')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS work_orders_one_open_return_per_origin');
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_origin_fk');
        DB::statement('DROP INDEX IF EXISTS work_orders_id_customer_device_unique');
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_warranty_check');
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_origin_check');
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_kind_check');

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropIndex(['origin_order_id']);
            $table->dropColumn(['kind', 'origin_order_id', 'warranty_until']);
        });
    }
};

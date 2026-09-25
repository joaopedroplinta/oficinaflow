<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->unique()->constrained()->restrictOnDelete();
            $table->string('decision');
            $table->string('customer_name');
            $table->string('channel');
            $table->timestampTz('decided_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE quote_decisions ADD CONSTRAINT quote_decisions_decision_check CHECK (decision IN ('approved', 'rejected'))");
        DB::statement("ALTER TABLE quote_decisions ADD CONSTRAINT quote_decisions_channel_check CHECK (channel IN ('phone', 'whatsapp', 'in_person', 'email', 'other'))");

        // A decisão é histórico: continua valendo como registro mesmo depois que a versão é substituída.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION quote_decisions_immutable_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'quote decisions are history and cannot be changed or deleted' USING ERRCODE = 'check_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER quote_decisions_immutable BEFORE UPDATE OR DELETE ON quote_decisions FOR EACH ROW EXECUTE FUNCTION quote_decisions_immutable_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_decisions');
        DB::unprepared('DROP FUNCTION IF EXISTS quote_decisions_immutable_guard()');
    }
};

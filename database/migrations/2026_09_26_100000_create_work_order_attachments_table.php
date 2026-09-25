<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            // Nome aleatório no disco privado; o nome que o usuário enviou fica só para exibir.
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedInteger('size_bytes');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('work_order_id');
        });

        DB::statement("ALTER TABLE work_order_attachments ADD CONSTRAINT work_order_attachments_mime_check CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'application/pdf'))");
        DB::statement('ALTER TABLE work_order_attachments ADD CONSTRAINT work_order_attachments_size_check CHECK (size_bytes > 0 AND size_bytes <= 10485760)');

        // Anexo é registro da ordem: não se edita nem se apaga (PLANO §13.1).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION work_order_attachments_append_only_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'work order attachments are append-only' USING ERRCODE = 'check_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER work_order_attachments_append_only BEFORE UPDATE OR DELETE ON work_order_attachments FOR EACH ROW EXECUTE FUNCTION work_order_attachments_append_only_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_attachments');
        DB::unprepared('DROP FUNCTION IF EXISTS work_order_attachments_append_only_guard()');
    }
};

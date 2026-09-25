<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderEventType;
use App\Exceptions\AttachmentLimitReached;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Models\WorkOrderEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AddAttachment
{
    public function handle(WorkOrder $order, UploadedFile $file, User $actor): WorkOrderAttachment
    {
        $disk = config('filesystems.attachments');

        return DB::transaction(function () use ($order, $file, $actor, $disk) {
            // O lock da ordem serializa uploads simultâneos: sem ele dois pedidos passariam do limite juntos.
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (WorkOrderAttachment::where('work_order_id', $order->id)->count() >= WorkOrderAttachment::MAX_PER_ORDER) {
                throw new AttachmentLimitReached;
            }

            // Nome aleatório, extensão vinda do conteúdo (não do nome enviado).
            // ponytail: grava com o lock da ordem preso; em disco remoto (S3) isso segura a ordem por segundos.
            // Para tirar, gravar depois do count e reduzir a janela até o commit.
            $path = $file->store("work-orders/{$order->id}", $disk);

            try {
                $attachment = WorkOrderAttachment::create([
                    'work_order_id' => $order->id,
                    'uploaded_by' => $actor->id,
                    'path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                ]);

                WorkOrderEvent::create([
                    'work_order_id' => $order->id,
                    'actor_id' => $actor->id,
                    'type' => WorkOrderEventType::AttachmentAdded,
                    'metadata' => ['attachment_id' => $attachment->id, 'name' => $attachment->original_name],
                ]);

                $attachment->load('uploader');
            } catch (Throwable $e) {
                // Rollback do banco não apaga arquivo: sem isso sobraria órfão no disco.
                Storage::disk($disk)->delete($path);
                throw $e;
            }

            return $attachment;
        });
    }
}

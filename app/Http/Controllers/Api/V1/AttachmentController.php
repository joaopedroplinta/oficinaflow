<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\WorkOrders\AddAttachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Resources\WorkOrderAttachmentResource;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function index(WorkOrder $workOrder): AnonymousResourceCollection
    {
        Gate::authorize('view', $workOrder);

        return WorkOrderAttachmentResource::collection($workOrder->attachments()->with('uploader')->orderBy('id')->get());
    }

    public function store(StoreAttachmentRequest $request, WorkOrder $workOrder, AddAttachment $add): JsonResponse
    {
        $attachment = $add->handle($workOrder, $request->file('file'), $request->user());

        return (new WorkOrderAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    /** O arquivo só sai por aqui, depois de autorizar; o disco não tem URL pública. */
    public function download(WorkOrder $workOrder, WorkOrderAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $workOrder);

        // Tipo vem da lista fechada validada no upload; nosniff impede o navegador de reinterpretar o conteúdo.
        return Storage::disk(config('filesystems.attachments'))->response(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff'],
            'inline',
        );
    }
}

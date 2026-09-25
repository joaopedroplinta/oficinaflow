<?php

namespace App\Http\Requests;

use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use Illuminate\Foundation\Http\FormRequest;

class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('addAttachment', $this->route('workOrder') ?? WorkOrder::class);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:'.implode(',', WorkOrderAttachment::MIME_TYPES), 'max:'.WorkOrderAttachment::MAX_KILOBYTES],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Escolha um arquivo.',
            'file.uploaded' => 'Não foi possível enviar o arquivo. Ele pode passar do tamanho máximo de 10 MB.',
            'file.mimetypes' => 'Envie uma imagem (JPEG, PNG ou WebP) ou um PDF.',
            'file.max' => 'O arquivo passa de 10 MB.',
        ];
    }
}

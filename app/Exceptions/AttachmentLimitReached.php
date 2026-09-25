<?php

namespace App\Exceptions;

use App\Models\WorkOrderAttachment;

class AttachmentLimitReached extends DomainConflict
{
    public function __construct()
    {
        parent::__construct('Esta ordem já tem '.WorkOrderAttachment::MAX_PER_ORDER.' anexos, o máximo permitido.');
    }

    public function errorCode(): string
    {
        return 'ATTACHMENT_LIMIT';
    }
}

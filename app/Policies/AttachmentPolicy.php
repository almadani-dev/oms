<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class AttachmentPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'attachments';
    }
}

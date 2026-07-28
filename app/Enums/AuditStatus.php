<?php

namespace App\Enums;

enum AuditStatus: string
{
    case Success = 'success';
    case Failure = 'failure';
}

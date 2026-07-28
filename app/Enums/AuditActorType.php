<?php

namespace App\Enums;

/**
 * Who/what performed an audited action. `User` covers every interactive,
 * browser-driven actor (including an unresolved/failed-login attempt — see
 * AuditActorContext::guest()); the other four cover every non-interactive
 * origin an audit event can come from. Never fabricate IP/user-agent/route
 * metadata for the four non-`User` cases — see AuditActorContext.
 */
enum AuditActorType: string
{
    case User = 'user';
    case System = 'system';
    case Scheduler = 'scheduler';
    case Queue = 'queue';
    case Command = 'command';
}

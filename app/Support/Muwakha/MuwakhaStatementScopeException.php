<?php

namespace App\Support\Muwakha;

use RuntimeException;

/**
 * Thrown when a Family Account Statement request asks for something outside
 * the scope the route's family actually owns.
 *
 * WHY AN EXCEPTION AND NOT A SILENT NARROWING. The statement's family is
 * fixed by the route. Everything else — the Account filter, the Project
 * filter — arrives from the browser and can be forged. Quietly dropping an
 * out-of-scope value would return a report that looks legitimate while
 * answering a different question than the one the operator (or the attacker)
 * asked, which is exactly how one family's ledger leaks into another family's
 * screen. Every call site turns this into a 403.
 *
 * The two scopes are deliberately different in kind:
 *
 *  - ACCOUNT scope is OWNERSHIP: `muwakha_family_accounts`, and nothing else.
 *    Never the Account's name, never its number — `accounts.account_code` is
 *    intentionally not unique across OMS.
 *  - PROJECT scope is the set of Projects the family's own mapped-Account
 *    MOVEMENTS actually resolve to through the authoritative financial path.
 *    A Project the family is merely LINKED to via `muwakha_family_projects`
 *    is not in scope: that link says nothing about whether any transaction on
 *    these Accounts belongs to that Project.
 */
final class MuwakhaStatementScopeException extends RuntimeException
{
    public static function accountNotMapped(int $accountId, int $familyId): self
    {
        return new self("Account {$accountId} is not mapped to Muwakha family {$familyId}.");
    }

    public static function projectOutOfScope(int $projectId, int $familyId): self
    {
        return new self("Project {$projectId} is not present in the movement scope of Muwakha family {$familyId}.");
    }

    public static function invalidDateRange(): self
    {
        return new self('The Family Account Statement date range is reversed.');
    }
}

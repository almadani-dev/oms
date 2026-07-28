<?php

namespace App\Services\Audit;

use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The one place ambient "who is acting right now" is turned into an
 * AuditActorContext (OMS Task 9B.2). AuditActorContext itself deliberately
 * has no auth()/request()-reading factory — it is a pure snapshot — so this
 * thin resolver exists instead of every CRUD call site re-deriving the same
 * three branches slightly differently.
 *
 * Request metadata (IP/user-agent/route/method) is attached only when a real
 * routed HTTP request exists. `runningInConsole()` is deliberately NOT the
 * test used: under `artisan` the container still resolves a synthetic
 * Request whose ip()/userAgent() are fabricated defaults, and it is also
 * true under PHPUnit even while a genuine routed request is being handled.
 * A resolved route is the honest signal, and it keeps
 * AuditActorContext's "never fabricated" guarantee intact either way.
 */
final class AuditActorResolver
{
    public function resolve(): AuditActorContext
    {
        $user = Auth::user();
        $user = $user instanceof User ? $user : null;
        $request = $this->httpRequest();

        if ($user !== null) {
            return AuditActorContext::forUser($user, $request);
        }

        return $request !== null
            ? AuditActorContext::guest($request)
            : AuditActorContext::command();
    }

    private function httpRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        if (! $request instanceof Request) {
            return null;
        }

        return $request->route() !== null ? $request : null;
    }
}

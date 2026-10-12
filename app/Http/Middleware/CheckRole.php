<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request, allowing users whose role is one of the given roles.
     * Super admins can access every role-restricted route.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null || ! ($user->hasRole(RoleName::SuperAdmin) || $user->hasRole(...$roles))) {
            abort(403, 'Unauthorized Access');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits how often a password reset can be asked for or submitted from one address, so the form cannot be used to
 * flood someone's inbox or to guess reset links. The reset routes belong to Fortify, so the limit is applied here,
 * by route name, instead of on the routes themselves.
 */
class ThrottlePasswordReset
{
    private const ATTEMPTS_PER_MINUTE = 5;

    public function __construct(private ThrottleRequests $throttle) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->routeIs('password.email', 'password.update')) {
            return $next($request);
        }

        return $this->throttle->handle($request, $next, self::ATTEMPTS_PER_MINUTE, 1, 'password-reset');
    }
}

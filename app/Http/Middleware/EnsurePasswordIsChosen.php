<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone whose password was set by an administrator may only choose their own before doing anything else. The
 * password page, the confirmation it asks for, and logging out stay open so there is always a way forward.
 */
class EnsurePasswordIsChosen
{
    public const MESSAGE = 'An administrator set this password for you. Please choose your own now.';

    /** @var list<string> */
    private const OPEN_ROUTES = ['security.edit', 'user-password.update', 'password.confirm', 'password.confirm.store', 'password.confirmation', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->must_change_password || $request->routeIs(...self::OPEN_ROUTES)) {
            return $next($request);
        }

        // A scanner or a search box is not a page, so it needs a plain answer it can show, not a redirect.
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        // They have only just logged in with this password, and the form they are sent to asks for it again, so the
        // extra confirmation step would only be in the way.
        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->route('security.edit')->with('status', self::MESSAGE);
    }
}

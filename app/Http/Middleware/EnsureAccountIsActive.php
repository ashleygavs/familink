<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public const MESSAGE = 'This account is deactivated. Please contact your administrator.';

    /**
     * Sign out anyone whose account was deactivated, even if they are already logged in.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // A scanner or a search box is not a page, so it needs a plain answer it can show, not a redirect to log in.
            if ($request->expectsJson()) {
                return response()->json(['message' => self::MESSAGE], 401);
            }

            return redirect()->route('login')->with('status', self::MESSAGE);
        }

        return $next($request);
    }
}

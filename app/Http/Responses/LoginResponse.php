<?php

namespace App\Http\Responses;

use App\Enums\RoleName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

class LoginResponse implements LoginResponseContract
{
    /**
     * Redirect the user to the landing page that matches their role.
     *
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $role = RoleName::tryFrom((string) $request->user()?->role?->name);

        return redirect()->intended($role?->homePath() ?? Fortify::redirects('login'));
    }
}

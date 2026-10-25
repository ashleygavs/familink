<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Responses\FailedPasswordResetLinkRequestResponse;

/**
 * An email with no account gets the same answer as one with an account, so the form cannot be used to find out
 * who has an account. Other failures, such as asking too often, are still shown.
 */
class PasswordResetLinkFailedResponse implements FailedPasswordResetLinkRequestResponseContract
{
    public function __construct(private string $status) {}

    public function toResponse($request)
    {
        if ($this->status === Password::INVALID_USER) {
            return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_LINK_SENT])->toResponse($request);
        }

        return (new FailedPasswordResetLinkRequestResponse($this->status))->toResponse($request);
    }
}

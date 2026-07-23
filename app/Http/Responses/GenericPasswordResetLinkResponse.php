<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;

class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    /**
     * Return the same public response whether or not the email belongs to an account.
     */
    public function toResponse($request)
    {
        $message = trans(Password::RESET_LINK_SENT);

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message])
            : back()->with('status', $message);
    }
}

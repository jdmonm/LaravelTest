<?php

namespace App\Exceptions;

use RuntimeException;

class SocialAuthenticationException extends RuntimeException
{
    public static function googleAccountIsNotEligible(): self
    {
        return new self('This Google account cannot be used to sign in.');
    }

    public static function googleAccountRequiresLinking(): self
    {
        return new self('This Google account is not linked. Sign in with your existing account and connect Google from Security settings.');
    }

    public static function googleAccountAlreadyLinked(): self
    {
        return new self('This Google account is already linked to another Library Desk account.');
    }
}

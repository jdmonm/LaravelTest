<?php

namespace App\Exceptions;

use RuntimeException;

class LibraryAssistantUnavailableException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 503)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('The Library Desk assistant is not configured yet.');
    }

    public static function providerUnavailable(): self
    {
        return new self('The Library Desk assistant is temporarily unavailable. Please try again.');
    }
}

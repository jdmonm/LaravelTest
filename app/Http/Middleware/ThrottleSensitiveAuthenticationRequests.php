<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottleSensitiveAuthenticationRequests
{
    public function __construct(private readonly ThrottleRequests $throttleRequests) {}

    /**
     * Apply named rate limiters only to Fortify actions susceptible to abuse.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $limiter = match ($request->route()?->getName()) {
            'password.email' => 'password-reset',
            'register.store' => 'registration',
            'password.confirm.store' => 'password-confirmation',
            default => null,
        };

        return $limiter === null
            ? $next($request)
            : $this->throttleRequests->handle($request, $next, $limiter);
    }
}

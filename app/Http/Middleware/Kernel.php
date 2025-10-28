<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * @var array
     */
    protected $middleware = [
        // ...
        \App\Http\Middleware\HandleCors::class,
        

        'web' => [
            // ... other middleware
            \Illuminate\Http\Middleware\HandleCors::class,
        ],

        'api' => [
            \Illuminate\Http\Middleware\HandleCors::class,
            // ... other middleware
        ],
    ];

    // Ensure ordering so HandleCors runs before session/CSRF
    protected $middlewarePriority = [
        \App\Http\Middleware\HandleCors::class, // <-- ensure this comes before StartSession
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
        // ...existing ordering...
    ];
}

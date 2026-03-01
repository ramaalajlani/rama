<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        /*
        |--------------------------------------------------------------------------
        | CSRF
        |--------------------------------------------------------------------------
        | ✅ API خارج CSRF لأن الفرونت منفصل (Postman/Mobile)
        */
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        /*
        |--------------------------------------------------------------------------
        | ✅ IMPORTANT: Stateless API
        |--------------------------------------------------------------------------
        | ❌ لا تستخدم statefulApi هنا لأنك تعمل Bearer Token via Cookie
        | ومع Postman رح يسبب Unauthenticated بسبب متطلبات SPA/CSRF.
        */
        // $middleware->statefulApi();

        /*
        |--------------------------------------------------------------------------
        | ✅ API Middleware Order (Laravel 11)
        |--------------------------------------------------------------------------
        | ✅ نضيف Middleware تحويل Cookie -> Authorization Bearer كـ PREPEND
        | حتى يشتغل قبل أي Middleware أخرى ضمن api group (وخاصة قبل auth:sanctum).
        */
        $middleware->api(prepend: [
            \App\Http\Middleware\BearerTokenFromCookie::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | ✅ Aliases (Spatie Permission)
        |--------------------------------------------------------------------------
        */
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
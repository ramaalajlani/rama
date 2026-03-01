<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class BearerTokenFromCookie
{
    public function handle(Request $request, Closure $next)
    {
        // إذا في Authorization موجود، لا تغيّر
        if ($request->headers->has('Authorization')) {
            return $next($request);
        }

        // اقرأ Sanctum token من الكوكي
        $token = $request->cookie('access_token');

        if (is_string($token) && $token !== '') {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        return $next($request);
    }
}
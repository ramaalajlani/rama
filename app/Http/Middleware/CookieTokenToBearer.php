<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CookieTokenToBearer
{
    public function handle(Request $request, Closure $next)
    {
        // إذا في Authorization جاهز لا تلمسه
        if ($request->headers->has('Authorization')) {
            return $next($request);
        }

        // اقرأ التوكن من الكوكي اللي أنت بتستخدمها
        $token = $request->cookie('access_token');

        if ($token) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        return $next($request);
    }
}
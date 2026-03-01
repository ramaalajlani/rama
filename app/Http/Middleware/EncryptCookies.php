<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * Cookies التي لا نريد تشفيرها (لازم access_token حتى نقرأه بسهولة)
     */
    protected $except = [
        'access_token',
        'auth',
        'XSRF-TOKEN',
    ];
}
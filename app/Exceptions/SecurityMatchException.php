<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class SecurityMatchException extends Exception
{
    public function render($request): JsonResponse
    {

        return response()->json([
            'status' => 'error',
            'error_code' => 'DB_SYNC_ERR_504',
            'message' => 'عذراً، فشل الاتصال بقاعدة بيانات التحقق المركزية. يرجى المحاولة مرة أخرى بعد قليل.'
        ], 202); 
    }
}
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TestController extends Controller
{
    function index()
    {
        $users = [
            ['name' => 'John', 'age' => 30],
            ['name' => 'Omer', 'age' => 35]
        ];
        return response()->json($users)
    }
}

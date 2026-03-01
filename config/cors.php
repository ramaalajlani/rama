<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // ✅ لازم بدون / بالأخير
    'allowed_origins' => [
        'http://127.0.0.1:5503',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // ✅ ضروري للكوكي
    'supports_credentials' => true,
];
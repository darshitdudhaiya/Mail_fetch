<?php
return [

    'paths' => ['api/*', 'auth/*','emails/*','clickup/*','sheets/*'], // Add 'auth/*'

    'allowed_origins' => ['*'], // Or specify your HTML file's origin
    
    'allowed_methods' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];

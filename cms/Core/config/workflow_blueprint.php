<?php

return [
    'allowed_categories' => [
        'service',
        'job',
        'command',
        'event',
    ],

    'allowed_job_methods' => [
        'handle',
        'dispatch',
        'dispatchSync',
        'dispatchAfterResponse',
        'onQueue',
        'onConnection',
        'delay',
    ],

    'denied_method_prefixes' => [
        'assert',
        'withFake',
        '__',
    ],

    'denied_parameter_types' => [
        'Illuminate\\Http\\Request',
    ],

    'ui_defaults' => [
        'hidden' => false,
        'is_recommended' => false,
    ],
];

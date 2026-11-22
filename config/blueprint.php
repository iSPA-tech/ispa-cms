<?php

return [
    'discovery' => [
        // Paths to scan for callable blueprints
        'paths' => [
            'app/Services',
            'app/Jobs',
            'app/Console/Commands',
            'app/Events',
            // module service paths (wildcards are supported)
            'cms,/*/app/Services',
        ],

        // Opt-in discovery: only classes with public $isDiscovery = true are included.
        // Set to false to fall back to legacy behaviour (still skips when isDiscovery is false).
        'opt_in_only' => env('BLUEPRINT_DISCOVERY_OPT_IN_ONLY', true),

        // Warn in non-production when a class is scanned but missing $isDiscovery=true.
        'warn_missing_opt_in' => env('BLUEPRINT_DISCOVERY_WARN_MISSING', env('APP_ENV') !== 'production'),
    ],
];

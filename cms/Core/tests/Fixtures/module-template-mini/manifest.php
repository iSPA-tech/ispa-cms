<?php

// Test fixture for cms:make-module: one file per kind of placeholder and marker.
return [
    'lite' => [
        'module.json',
        'app/Models/SkeletonThing.php',
        'database/migrations/2026_01_01_000000_create_skeleton_things_table.php',
    ],
    'normal' => [
        'admin/pages/things.vue',
    ],
    'full' => [
        'routes/web.php',
    ],
];

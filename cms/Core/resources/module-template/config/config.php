<?php

return [
    'name' => 'Skeleton',
    // Interface => implementation pairs bound as singletons when the app has booted.
    'bindings' => [],
    // @mode full
    // Address that receives a mail each time a thing is saved; leave it empty to send no mail.
    'notify_email' => env('SKELETON_NOTIFY_EMAIL'),
    // skeleton:purge-trashed removes trashed things older than this many days.
    'purge_after_days' => 30,
    'public' => [
        // Blade layout of the public pages; empty uses the active project's layout.index.
        'layout' => null,
    ],
    // @endmode
];

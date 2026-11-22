<?php

/**
 * Short number units of `formatPrice()` (app/Helpers/helpers.php): "1,5 tỷ", "250 tr". Public pages print them too
 * (for_web), so a site without these rows showed the raw key. The importer only reads `translate-admin-*.php` sidecars,
 * hence the file name.
 */

$entry = static function (string $name, string $en, string $vi): array {
    return [
        // No 'id': the importer finds rows by name, and an id only links an existing row that may hold another key.
        'modules' => ['Core'],
        'group_name' => 'text',
        'name' => $name,
        'text' => [
            'en' => $en,
            'vi' => $vi,
        ],
        'for_web' => 1,
        'for_adm' => 1,
        'for_app' => 0,
        'status' => 'status_published',
        'created_at' => '2026-11-22 23:00:00',
        'updated_at' => '2026-11-22 23:00:00',
    ];
};

return [
    $entry('text.utils.unit_m', 'm', 'tr'),
    $entry('text.utils.unit_b', 'b', 'tỷ'),
];

<?php

/**
 * Dashboard top-targets coverage line translations.
 */

$entry = static function (int $id, string $name, string $en, string $vi): array {
    return [
        // No 'id': the importer finds rows by name, and an id only links an existing row that may hold another key.
        'modules' => ['Core'],
        'group_name' => 'text',
        'name' => $name,
        'text' => [
            'en' => $en,
            'vi' => $vi,
        ],
        'for_web' => 0,
        'for_adm' => 1,
        'for_app' => 0,
        'status' => 'status_published',
        'created_at' => '2026-10-29 09:00:00',
        'updated_at' => '2026-10-29 09:00:00',
    ];
};

return [
    $entry(999970, 'text.rp.top-coverage', 'Top {count}: {views} views · {percent}% of {total} site-wide views ({from}–{to}). The rest is spread across many pages with few views.', '{count} mục đầu: {views} lượt · {percent}% trên {total} lượt xem toàn site ({from}–{to}). Phần còn lại rải trên nhiều trang ít lượt xem.'),
    $entry(999971, 'text.rp.top-coverage-window', 'Top {count}: {views} views ({from}–{to}).', '{count} mục đầu: {views} lượt ({from}–{to}).'),
    $entry(999972, 'text.rp.top-coverage-sum', 'Top {count}: {views} views.', '{count} mục đầu: {views} lượt.'),
];

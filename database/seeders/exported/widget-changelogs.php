<?php

/**
 * What-changed notes of hub widget versions, shown in the admin widget update dialog ("Có gì mới").
 *
 * Hand-written, never generated. Format:
 *
 *   'widget_key' => [
 *       version (int, CalVer YYYYMMNN, shown as YYYY.MM.NN) => [
 *           ['type' => 'added' | 'changed' | 'fixed', 'vi' => 'Vietnamese text', 'en' => 'English text'],
 *           ...
 *       ],
 *   ],
 *
 * Rules (checked by Cms\Core\Support\Widget\WidgetChangeNotes on every load; a broken entry fails the build):
 * - at least one item per version, at most 50;
 * - both `vi` and `en` present and non-empty, at most 300 characters each, written for site operators (plain words,
 *   no internal codes);
 * - no other keys.
 *
 * Add the entry before running `cms:widget-bundle-build`; the build writes it next to the bundle as
 * `<bundle>.notes.json` (unsigned) and `cms:platform-widget-publish` stores it on the hub. Notes never go inside the
 * signed bundle. For a version already on the hub: `cms:widget-bundle-build <key> --hub-version=<v> --notes-only`,
 * then on the hub `cms:platform-widget-notes <key> <v> <file>`.
 */
return [
    'core.widget-1' => [
        20261101 => [
            ['type' => 'added', 'vi' => 'Thêm kiểu hiển thị dùng chung "Ảnh và nội dung" cho mọi website.', 'en' => 'Added the shared "Image and Text" design for every website.'],
        ],
        20261102 => [
            ['type' => 'changed', 'vi' => 'Viết lại mô tả trong thư viện widget: khối hiển thị gì, bạn nhập gì và hợp với trang nào. Giao diện không đổi.', 'en' => 'The library description now says what the block shows, what you fill in and which page it suits. The design is unchanged.'],
        ],
    ],
    'core.widget-3' => [
        20261101 => [
            ['type' => 'added', 'vi' => 'Thêm kiểu hiển thị dùng chung "Trình chiếu banner" chạy trên mọi website.', 'en' => 'Added the shared "Banner Slider" design that runs on every website.'],
        ],
        20261102 => [
            ['type' => 'changed', 'vi' => 'Viết lại mô tả trong thư viện widget: khối hiển thị gì, bạn nhập gì và hợp với trang nào. Giao diện không đổi.', 'en' => 'The library description now says what the block shows, what you fill in and which page it suits. The design is unchanged.'],
        ],
    ],
    'core.widget-4' => [
        20261101 => [
            ['type' => 'added', 'vi' => 'Thêm kiểu hiển thị dùng chung "Lưới sản phẩm" cho mọi website.', 'en' => 'Added the shared "Product Grid" design for every website.'],
        ],
        20261102 => [
            ['type' => 'changed', 'vi' => 'Viết lại mô tả trong thư viện widget: khối hiển thị gì, bạn nhập gì và hợp với trang nào. Giao diện không đổi.', 'en' => 'The library description now says what the block shows, what you fill in and which page it suits. The design is unchanged.'],
        ],
    ],
    'core.widget-5' => [
        20261101 => [
            ['type' => 'added', 'vi' => 'Thêm kiểu hiển thị dùng chung "Địa điểm kèm địa chỉ và giờ mở cửa" cho mọi website.', 'en' => 'Added the shared "Location with Address and Hours" design for every website.'],
        ],
        20261102 => [
            ['type' => 'changed', 'vi' => 'Viết lại mô tả trong thư viện widget: khối hiển thị gì, bạn nhập gì và hợp với trang nào. Giao diện không đổi.', 'en' => 'The library description now says what the block shows, what you fill in and which page it suits. The design is unchanged.'],
        ],
    ],
    'core.widget-6' => [
        20261101 => [
            ['type' => 'added', 'vi' => 'Thêm kiểu hiển thị dùng chung "Giới thiệu hai khối" cho mọi website.', 'en' => 'Added the shared "About, Two Rows" design for every website.'],
        ],
        20261102 => [
            ['type' => 'changed', 'vi' => 'Viết lại mô tả trong thư viện widget: khối hiển thị gì, bạn nhập gì và hợp với trang nào. Giao diện không đổi.', 'en' => 'The library description now says what the block shows, what you fill in and which page it suits. The design is unchanged.'],
        ],
    ],
    'core.widget-2' => [
        20261101 => [
            ['type' => 'changed', 'vi' => 'Viết lại mô tả trong thư viện widget: khối hiển thị gì, bạn nhập gì và hợp với trang nào. Giao diện không đổi.', 'en' => 'The library description now says what the block shows, what you fill in and which page it suits. The design is unchanged.'],
        ],
    ],
    'core.widget-7' => [
        20261101 => [
            ['type' => 'changed', 'vi' => 'Viết lại mô tả trong thư viện widget: khối hiển thị gì, bạn nhập gì và hợp với trang nào. Giao diện không đổi.', 'en' => 'The library description now says what the block shows, what you fill in and which page it suits. The design is unchanged.'],
        ],
    ],
    'core.widget-8' => [
        20261101 => [
            ['type' => 'changed', 'vi' => 'Viết lại mô tả trong thư viện widget: khối hiển thị gì, bạn nhập gì và hợp với trang nào. Giao diện không đổi.', 'en' => 'The library description now says what the block shows, what you fill in and which page it suits. The design is unchanged.'],
        ],
    ],
    'core.widget-20' => [
        20261101 => [
            ['type' => 'changed', 'vi' => 'Viết lại mô tả trong thư viện widget: khối hiển thị gì, bạn nhập gì và hợp với trang nào. Giao diện không đổi.', 'en' => 'The library description now says what the block shows, what you fill in and which page it suits. The design is unchanged.'],
        ],
    ],
    'core.widget-21' => [
        20261101 => [
            ['type' => 'changed', 'vi' => 'Viết lại mô tả trong thư viện widget: khối hiển thị gì, bạn nhập gì và hợp với trang nào. Giao diện không đổi.', 'en' => 'The library description now says what the block shows, what you fill in and which page it suits. The design is unchanged.'],
        ],
    ],
];

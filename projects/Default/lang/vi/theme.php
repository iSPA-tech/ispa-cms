<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Copy of the Default theme (layout, menus, home). Site name, description and logo come from the SEO settings.
return [
    'menu' => [
        'label' => 'Menu chính',
        'open' => 'Menu',
        'close' => 'Đóng',
    ],
    'hero' => [
        'cta' => 'Đọc bài mới',
    ],
    'latest' => [
        'title' => 'Bài viết mới',
        'all' => 'Xem tất cả',
    ],
    'empty' => [
        'title' => 'Chưa có bài viết nào',
        'text' => 'Bài viết đăng trong trang quản trị sẽ hiện ở đây.',
        'admin' => 'Vào trang quản trị',
    ],
    'footer' => [
        'label' => 'Menu chân trang',
        'copyright' => '© :year :name',
        'powered' => 'Dùng iSPA CMS',
    ],
];

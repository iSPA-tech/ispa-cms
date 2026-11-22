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
        'label' => 'Main menu',
        'open' => 'Menu',
        'close' => 'Close',
    ],
    'hero' => [
        'cta' => 'Read the latest',
    ],
    'latest' => [
        'title' => 'Latest posts',
        'all' => 'View all',
    ],
    'empty' => [
        'title' => 'No posts yet',
        'text' => 'Posts you publish in the admin appear here.',
        'admin' => 'Open the admin',
    ],
    'footer' => [
        'label' => 'Footer menu',
        'copyright' => '© :year :name',
        'powered' => 'Built with iSPA CMS',
    ],
];

<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

return [
    // Label shown in backup paths and notices; null = none.
    'server_label' => env('BACKUP_SERVER_LABEL'),

    // Optional override of the site slug used in backup paths. When null the slug is derived lazily from
    // config('app.url') by Cms\Core\Models\Backup\Backup::siteSlug() (host, lower-case, [a-z0-9.-] only).
    'site_slug' => env('BACKUP_SITE_SLUG'),

    'staging_path' => env('BACKUP_STAGING_PATH', storage_path('backups/staging')),
    'local_root' => storage_path('backups/local'),
    'min_free_bytes' => 1073741824,
    'process_timeout' => (int) env('BACKUP_PROCESS_TIMEOUT', 780),
    'job_timeout' => 840,
    'queue' => env('BACKUP_QUEUE', 'default'),
    'max_retention_days' => 90,

    'binaries' => [
        'mysqldump' => env('BACKUP_BIN_MYSQLDUMP', 'mysqldump'),
        'mysql' => env('BACKUP_BIN_MYSQL', 'mysql'),
        'pg_dump' => env('BACKUP_BIN_PG_DUMP', 'pg_dump'),
        'pg_restore' => env('BACKUP_BIN_PG_RESTORE', 'pg_restore'),
        'psql' => env('BACKUP_BIN_PSQL', 'psql'),
    ],

    'oauth' => [
        'gdrive' => [
            'client_id' => env('BACKUP_GDRIVE_CLIENT_ID', ''),
            'client_secret' => env('BACKUP_GDRIVE_CLIENT_SECRET', ''),
        ],
        'onedrive' => [
            'client_id' => env('BACKUP_ONEDRIVE_CLIENT_ID', ''),
            'client_secret' => env('BACKUP_ONEDRIVE_CLIENT_SECRET', ''),
        ],
    ],
];

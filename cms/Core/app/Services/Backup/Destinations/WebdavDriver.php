<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup\Destinations;

use Cms\Core\Models\Backup\BackupDestination;

/**
 * WebDAV destination (NAS, Nextcloud, ...). HTTPS only. Both username and password are stored as encrypted
 * credentials; the disk itself is built by the 'webdav' Storage extension in CoreServiceProvider.
 */
class WebdavDriver implements DestinationDriver
{
    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'webdav';
    }

    /**
     * @inheritDoc
     */
    public function fields(): array
    {
        return [
            'base_uri' => ['type' => 'url', 'required' => true],
            'path_prefix' => ['type' => 'string', 'required' => false],
        ];
    }

    /**
     * @inheritDoc
     */
    public function credentialFields(): array
    {
        return ['username', 'password'];
    }

    /**
     * @inheritDoc
     */
    public function validate(array $config, #[\SensitiveParameter] array $credentials): array
    {
        $errors = [];

        $uri = $config['base_uri'] ?? null;
        $parts = is_string($uri) ? parse_url(trim($uri)) : false;
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ($parts['host'] ?? '') === '') {
            $errors[] = 'BACKUP_ENDPOINT_NOT_HTTPS';
        }

        foreach ($this->credentialFields() as $field) {
            $value = $credentials[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                $errors[] = 'BACKUP_CREDENTIALS_REQUIRED';
                break;
            }
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function diskConfig(BackupDestination $destination): array
    {
        $config = is_array($destination->config) ? $destination->config : [];
        $credentials = is_array($destination->credentials) ? $destination->credentials : [];

        return [
            'driver' => 'webdav',
            'baseUri' => (string) ($config['base_uri'] ?? ''),
            'userName' => (string) ($credentials['username'] ?? ''),
            'password' => (string) ($credentials['password'] ?? ''),
            'pathPrefix' => (string) ($config['path_prefix'] ?? ''),
            'throw' => true,
        ];
    }
}

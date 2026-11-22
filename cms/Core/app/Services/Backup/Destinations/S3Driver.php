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
 * S3-compatible object storage (Cloudflare R2, AWS S3, MinIO, ...).
 */
class S3Driver implements DestinationDriver
{
    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 's3';
    }

    /**
     * @inheritDoc
     */
    public function fields(): array
    {
        return [
            'endpoint' => ['type' => 'url', 'required' => true],
            'region' => ['type' => 'string', 'required' => false, 'default' => 'auto'],
            'bucket' => ['type' => 'string', 'required' => true],
            'path_style' => ['type' => 'bool', 'required' => false, 'default' => false],
            'prefix' => ['type' => 'string', 'required' => false],
        ];
    }

    /**
     * @inheritDoc
     */
    public function credentialFields(): array
    {
        return ['access_key_id', 'secret_access_key'];
    }

    /**
     * @inheritDoc
     */
    public function validate(array $config, #[\SensitiveParameter] array $credentials): array
    {
        $errors = [];

        $endpoint = $config['endpoint'] ?? null;
        $parts = is_string($endpoint) ? parse_url(trim($endpoint)) : false;
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ($parts['host'] ?? '') === '') {
            $errors[] = 'BACKUP_ENDPOINT_NOT_HTTPS';
        }

        $bucket = $config['bucket'] ?? null;
        if (!is_string($bucket) || trim($bucket) === '') {
            $errors[] = 'BACKUP_BUCKET_REQUIRED';
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
        $region = $config['region'] ?? null;

        return [
            'driver' => 's3',
            'key' => (string) ($credentials['access_key_id'] ?? ''),
            'secret' => (string) ($credentials['secret_access_key'] ?? ''),
            'region' => is_string($region) && $region !== '' ? $region : 'auto',
            'bucket' => (string) ($config['bucket'] ?? ''),
            'endpoint' => (string) ($config['endpoint'] ?? ''),
            'use_path_style_endpoint' => (bool) ($config['path_style'] ?? false),
            'throw' => true,
        ];
    }
}

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
 * Google Drive destination. The operator sets the OAuth client id (config) and client secret (encrypted credential);
 * the refresh token is stored by the OAuth connection flow. The disk itself is built by the 'gdrive' Storage extension
 * in CoreServiceProvider and gets its access tokens from the OAuth connector, so no secret is part of the disk config.
 */
class GoogleDriveDriver implements DestinationDriver
{
    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'gdrive';
    }

    /**
     * @inheritDoc
     */
    public function fields(): array
    {
        return [
            'client_id' => ['type' => 'string', 'required' => true],
        ];
    }

    /**
     * @inheritDoc
     */
    public function credentialFields(): array
    {
        return ['client_secret', 'refresh_token'];
    }

    /**
     * @inheritDoc
     */
    public function validate(array $config, #[\SensitiveParameter] array $credentials): array
    {
        $clientId = $config['client_id'] ?? null;

        return !is_string($clientId) || trim($clientId) === '' ? ['BACKUP_OAUTH_CLIENT_MISSING'] : [];
    }

    /**
     * @inheritDoc
     */
    public function diskConfig(BackupDestination $destination): array
    {
        return [
            'driver' => 'gdrive',
            'destination_uuid' => (string) $destination->uuid,
            'throw' => true,
        ];
    }
}

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
 * SFTP destination (NAS, VPS). The server host key fingerprint is mandatory: there is no trust on first use.
 */
class SftpDriver implements DestinationDriver
{
    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'sftp';
    }

    /**
     * @inheritDoc
     */
    public function fields(): array
    {
        return [
            'host' => ['type' => 'string', 'required' => true],
            'port' => ['type' => 'int', 'required' => false, 'default' => 22],
            'root' => ['type' => 'string', 'required' => false],
            'username' => ['type' => 'string', 'required' => true],
            'host_fingerprint' => ['type' => 'string', 'required' => true],
        ];
    }

    /**
     * @inheritDoc
     */
    public function credentialFields(): array
    {
        return ['password', 'private_key', 'passphrase'];
    }

    /**
     * @inheritDoc
     */
    public function validate(array $config, #[\SensitiveParameter] array $credentials): array
    {
        $errors = [];

        if (!$this->filled($config['host'] ?? null)) {
            $errors[] = 'BACKUP_HOST_REQUIRED';
        }

        if (!$this->filled($config['username'] ?? null)) {
            $errors[] = 'BACKUP_USERNAME_REQUIRED';
        }

        $port = $config['port'] ?? null;
        if ($port !== null && $port !== '' && (!is_numeric($port) || (int) $port < 1 || (int) $port > 65535)) {
            $errors[] = 'BACKUP_PORT_INVALID';
        }

        if (!$this->filled($config['host_fingerprint'] ?? null)) {
            $errors[] = 'BACKUP_SFTP_FINGERPRINT_REQUIRED';
        }

        if (!$this->filled($credentials['password'] ?? null) && !$this->filled($credentials['private_key'] ?? null)) {
            $errors[] = 'BACKUP_CREDENTIALS_REQUIRED';
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
        $port = $config['port'] ?? null;

        $disk = [
            'driver' => 'sftp',
            'host' => (string) ($config['host'] ?? ''),
            'port' => is_numeric($port) ? (int) $port : 22,
            'root' => (string) ($config['root'] ?? ''),
            'username' => (string) ($config['username'] ?? ''),
            'hostFingerprint' => (string) ($config['host_fingerprint'] ?? ''),
        ];

        if ($this->filled($credentials['private_key'] ?? null)) {
            $disk['privateKey'] = (string) $credentials['private_key'];
            if ($this->filled($credentials['passphrase'] ?? null)) {
                $disk['passphrase'] = (string) $credentials['passphrase'];
            }
        } else {
            $disk['password'] = (string) ($credentials['password'] ?? '');
        }

        $disk['throw'] = true;

        return $disk;
    }

    /**
     * Whether a value is a non-blank string.
     */
    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}

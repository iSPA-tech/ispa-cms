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
use InvalidArgumentException;

/**
 * A directory on the application server, kept away from web-served and application paths.
 */
class LocalDriver implements DestinationDriver
{
    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'local';
    }

    /**
     * @inheritDoc
     */
    public function fields(): array
    {
        return ['root' => ['type' => 'path', 'required' => true]];
    }

    /**
     * @inheritDoc
     */
    public function credentialFields(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function validate(array $config, #[\SensitiveParameter] array $credentials): array
    {
        $root = $config['root'] ?? null;
        if (!is_string($root) || trim($root) === '') {
            return ['BACKUP_LOCAL_ROOT_REQUIRED'];
        }
        if (!str_starts_with($root, '/') || str_contains($root, "\0")) {
            return ['BACKUP_LOCAL_ROOT_INVALID'];
        }

        return $this->isForbidden($root) ? ['BACKUP_LOCAL_ROOT_FORBIDDEN'] : [];
    }

    /**
     * @inheritDoc
     *
     * @throws InvalidArgumentException With a BACKUP_LOCAL_ROOT_* code when the root is not allowed
     */
    public function diskConfig(BackupDestination $destination): array
    {
        $config = is_array($destination->config) ? $destination->config : [];
        $errors = $this->validate($config, []);
        if ($errors !== []) {
            throw new InvalidArgumentException($errors[0]);
        }

        $root = rtrim((string) $config['root'], '/');
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new InvalidArgumentException('BACKUP_LOCAL_ROOT_INVALID');
        }

        return [
            'driver' => 'local',
            'root' => $root,
            'throw' => true,
            'visibility' => 'private',
            'directory_visibility' => 'private',
            'permissions' => [
                'file' => ['public' => 0600, 'private' => 0600],
                'dir' => ['public' => 0700, 'private' => 0700],
            ],
        ];
    }

    /**
     * Whether a root lies in a protected area (public, storage/app or the app tree) outside storage/backups.
     */
    private function isForbidden(string $root): bool
    {
        $path = $this->normalize($root);

        if ($this->isInside($path, $this->normalize(storage_path('backups')))) {
            return false;
        }

        foreach ([public_path(), storage_path('app'), base_path()] as $protected) {
            if ($this->isInside($path, $this->normalize($protected))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Path-prefix check with a separator boundary (equal counts as inside).
     */
    private function isInside(string $path, string $base): bool
    {
        return $path === $base || str_starts_with($path, rtrim($base, '/') . '/');
    }

    /**
     * Resolve `.`/`..` segments and symlinks (via the deepest existing ancestor) of an absolute path.
     */
    private function normalize(string $path): string
    {
        $stack = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($stack);
                continue;
            }
            $stack[] = $segment;
        }

        $tail = [];
        while ($stack !== []) {
            $real = @realpath('/' . implode('/', $stack));
            if ($real !== false) {
                return rtrim($real, '/') . ($tail !== [] ? '/' . implode('/', $tail) : '');
            }
            array_unshift($tail, array_pop($stack));
        }

        return '/' . implode('/', $tail);
    }
}

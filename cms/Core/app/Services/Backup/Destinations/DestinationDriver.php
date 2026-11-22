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
 * A backup destination type (S3, local, ...): its settings, validation and Laravel disk configuration.
 */
interface DestinationDriver
{
    /**
     * Driver key stored in `backup_destinations.driver`.
     */
    public function key(): string;

    /**
     * Non-secret config field specs, keyed by field name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(): array;

    /**
     * Names of the credential fields kept in the encrypted credentials column.
     *
     * @return array<int, string>
     */
    public function credentialFields(): array;

    /**
     * Validate config and credentials.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $credentials
     * @return array<int, string> Machine error codes; empty when valid
     */
    public function validate(array $config, #[\SensitiveParameter] array $credentials): array;

    /**
     * Build the Laravel disk configuration for a destination (contains secrets; never log or return it).
     *
     * @return array<string, mixed>
     */
    public function diskConfig(BackupDestination $destination): array;
}

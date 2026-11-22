<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

/**
 * Outcome of InstallPreflight::check(): blocking problems, warnings and the changes an install would make.
 *
 * Each blocking problem and warning is `['code' => string, 'message' => string, 'details' => array]`.
 */
final class PreflightResult
{
    /**
     * @param list<array{code: string, message: string, details: array<string, mixed>}> $blocking
     * @param list<array{code: string, message: string, details: array<string, mixed>}> $warnings
     * @param list<string>                                                              $changes human-readable list of what the install will do
     * @param string                                                                    $kind `install` or `upgrade` (an installed runtime module gets a newer version)
     * @param string|null                                                               $previousVersion installed version an upgrade replaces
     * @param array<string, mixed> $backup default of the pre-install backup switch: `recommended`, `reason`, `new_migrations`, `last_backup` {at, source}
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly array $blocking,
        public readonly array $warnings,
        public readonly array $changes,
        public readonly string $kind = 'install',
        public readonly ?string $previousVersion = null,
        public readonly array $backup = [],
    ) {
    }

    /**
     * True when nothing blocks the install.
     */
    public function canInstall(): bool
    {
        return $this->blocking === [];
    }

    /**
     * True when the package upgrades an installed runtime module.
     */
    public function isUpgrade(): bool
    {
        return $this->kind === 'upgrade';
    }

    /**
     * Machine codes of the blocking problems, in order.
     *
     * @return list<string>
     */
    public function blockingCodes(): array
    {
        return array_column($this->blocking, 'code');
    }

    /**
     * Machine codes of the warnings, in order.
     *
     * @return list<string>
     */
    public function warningCodes(): array
    {
        return array_column($this->warnings, 'code');
    }

    /**
     * API shape of the result.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'kind' => $this->kind,
            'previous_version' => $this->previousVersion,
            'can_install' => $this->canInstall(),
            'blocking' => $this->blocking,
            'warnings' => $this->warnings,
            'changes' => $this->changes,
            'backup' => $this->backup,
        ];
    }
}

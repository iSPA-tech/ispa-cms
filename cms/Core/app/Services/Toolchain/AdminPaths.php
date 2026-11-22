<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Toolchain;

/**
 * Finds the Vue admin sources next to a site: `admin/` in the public (release) layout, `../base-admin` in the private
 * workspace. The base path is injectable so tests never look at the real checkout.
 */
class AdminPaths
{
    /**
     * @param string|null $basePath Site base path (default: base_path())
     */
    public function __construct(private readonly ?string $basePath = null)
    {
    }

    /**
     * The site base path.
     */
    public function base(): string
    {
        return rtrim($this->basePath ?? base_path(), '/\\');
    }

    /**
     * The admin source folder, or null when there is none (a folder counts only when it has a package.json).
     */
    public function root(): ?string
    {
        foreach (['/admin', '/../base-admin'] as $relative) {
            $candidate = $this->base().$relative;
            if (is_file($candidate.'/package.json')) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
    }
}

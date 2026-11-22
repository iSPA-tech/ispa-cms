<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\Language;
use Cms\Core\Interfaces\LanguageInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Class LanguageRepository
 * Eloquent repository for the Language model.
 *
 * @extends RepositoriesAbstract
 * @implements LanguageInterface
 * @see Language
 *
 * @method \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Builder|array|null find(int $id, array $select = ['*'], array $with = [])
 * @method mixed get(array $params)
 * @package Cms\Core\Repositories
 */
class LanguageRepository extends RepositoriesAbstract implements LanguageInterface
{
    /**
     * Initialize the repository and bind the Language model.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct()
    {
        $this->setModel(Language::class);
    }
}

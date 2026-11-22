<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Repositories\Eloquent;

use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use App\Models\Contact;
use App\Repositories\Interfaces\ContactInterface;

class ContactRepository extends RepositoriesAbstract implements ContactInterface
{
    public function __construct() {
        $this->setModel(Contact::class);
    }
}

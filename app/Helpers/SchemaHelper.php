<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class SchemaHelper
{
    public static function dropIndexIfExists(Blueprint $table, string $tableName, string $indexName): void
    {
        // Ensure table exists
        if (!Schema::hasTable($tableName)) {
            return;
        }

        $connection = DB::connection();
        $dbName = $connection->getDatabaseName();

        $indexExists = $connection->table('information_schema.statistics')
            ->where('table_schema', $dbName)
            ->where('table_name', $tableName)
            ->where('index_name', $indexName)
            ->exists();

        if ($indexExists) {
            $table->dropIndex($indexName);
        }
    }
}

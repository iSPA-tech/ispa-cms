<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Constants\Tables;
use Cms\Content\Constants\ContentTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** @var array<string, bool> */
    private array $enabledModules = [];

    /**
     * @param  array<string, bool>  $enabledModules
     */
    public function setEnabledModules(array $enabledModules): void
    {
        $this->enabledModules = $enabledModules;
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        $connection = Schema::getConnection();
        $postsTable = $this->contentPostsTable();
        $totalNewsSql = $postsTable === null
            ? '0'
            : "(SELECT COUNT(`id`) FROM `{$postsTable}` WHERE `deleted_at` IS NULL)";

        $sql = "use `{$connection->getDatabaseName()}`;
DROP PROCEDURE IF EXISTS `" . Tables::GET_TOTAL_COUNTING_REPORT . "`;

CREATE PROCEDURE `" . Tables::GET_TOTAL_COUNTING_REPORT . "`()
BEGIN
    SELECT
        (SELECT COUNT(`id`) FROM `" . Tables::CONTACTS . "` WHERE `deleted_at` IS NULL) AS `total_contacts`,
        {$totalNewsSql} AS `total_news`,
        (SELECT COUNT(`id`) FROM `" . Tables::PLACE_ITEMS . "` WHERE `deleted_at` IS NULL) AS `total_items`,
        (SELECT SUM(`view`) FROM `" . Tables::TRACKS . "`) AS `total_views`,
        (SELECT COUNT(DISTINCT `session_id`) FROM `" . Tables::TRACKS . "`) AS `total_visitor`,
        (SELECT COUNT(`id`) FROM `" . Tables::TRACKS . "` WHERE `tags` LIKE '%\"scan\"%') AS `total_scan`,
        (SELECT COUNT(`id`) FROM `" . Tables::COMMENTS . "` WHERE `deleted_at` IS NULL) AS `total_comments`,
        (SELECT COUNT(`id`) FROM `" . Tables::COMMENTS . "` WHERE `created_at` > DATE_SUB(NOW(), INTERVAL 30 DAY) AND `deleted_at` IS NULL) AS `total_comments_new`,
        (SELECT COUNT(`id`) FROM `" . Tables::COMMENTS . "` WHERE (`status` IN ('pending', 'viewed')) AND `deleted_at` IS NULL) AS `total_comments_pending`,
        (SELECT COUNT(`id`) FROM `" . Tables::COMMENTS . "` WHERE `status` = 'confirmed' AND `deleted_at` IS NULL) AS `total_comments_confirmed`,
        (SELECT COUNT(`id`) FROM `" . Tables::COMMENTS . "` WHERE `status` = 'rejected' AND `deleted_at` IS NULL) AS `total_comments_rejected`;
END;";
        $connection->unprepared($sql);
    }

    private function contentPostsTable(): ?string
    {
        if (! $this->moduleIsEnabled('Content') || ! class_exists(ContentTableConstant::class)) {
            return null;
        }

        $table = ContentTableConstant::POSTS;

        return Schema::hasTable($table) ? $table : null;
    }

    private function moduleIsEnabled(string $module): bool
    {
        if ($this->enabledModules !== []) {
            return $this->enabledModules[$module] ?? false;
        }

        $path = base_path('modules_statuses.json');

        if (! is_file($path)) {
            return false;
        }

        $modules = json_decode((string) file_get_contents($path), true);

        return is_array($modules) && (bool) ($modules[$module] ?? false);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        $connection = Schema::getConnection();

        $sql = "use `{$connection->getDatabaseName()}`;
DROP PROCEDURE IF EXISTS `" . Tables::GET_TOTAL_COUNTING_REPORT . "`;
";

        $connection->unprepared($sql);
    }
};

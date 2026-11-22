<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Constants\Tables;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        $connection = Schema::getConnection();

        $sql = "use `{$connection->getDatabaseName()}`;
DROP PROCEDURE IF EXISTS `" . Tables::GET_DAILY_TRACKING . "`;

CREATE PROCEDURE `" . Tables::GET_DAILY_TRACKING . "`(
    IN `trackDate` DATE,
    IN `targetType` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
    IN `targetId` INT(11)
)
BEGIN
    DECLARE startDate DATETIME;
    DECLARE endDate DATETIME;

    SET startDate = CAST(CONCAT(trackDate, ' 00:00:00') AS DATETIME);
    SET endDate = CAST(CONCAT(trackDate, ' 23:59:59') AS DATETIME);

    SELECT
        IFNULL((
            SELECT COALESCE(SUM(`view`), 0)
            FROM `" . Tables::TRACKS . "`
            WHERE
                (`target_type` COLLATE utf8mb4_general_ci = `targetType` OR `targetType` IS NULL)
                AND (`target_id` = `targetId` OR `targetId` IS NULL)
                AND (`created_at` BETWEEN startDate AND endDate OR `trackDate` IS NULL)
        ), 0) AS `total_views`,

        IFNULL((
            SELECT COUNT(DISTINCT `session_id`)
            FROM `" . Tables::TRACKS . "`
            WHERE
                (`target_type` COLLATE utf8mb4_general_ci = `targetType` OR `targetType` IS NULL)
                AND (`target_id` = `targetId` OR `targetId` IS NULL)
                AND (`created_at` BETWEEN startDate AND endDate OR `trackDate` IS NULL)
        ), 0) AS `total_visitor`,

        IFNULL((
            SELECT COUNT(`id`)
            FROM `" . Tables::TRACKS . "`
            WHERE
                (`target_type` COLLATE utf8mb4_general_ci = `targetType` OR `targetType` IS NULL)
                AND (`target_id` = `targetId` OR `targetId` IS NULL)
                AND (`tags` LIKE '%\"scan\"%')
                AND (`created_at` BETWEEN startDate AND endDate OR `trackDate` IS NULL)
        ), 0) AS `total_scan`;
END;
";

        $connection->unprepared($sql);
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
DROP PROCEDURE IF EXISTS `" . Tables::GET_DAILY_TRACKING . "`;
";

        $connection->unprepared($sql);
    }
};

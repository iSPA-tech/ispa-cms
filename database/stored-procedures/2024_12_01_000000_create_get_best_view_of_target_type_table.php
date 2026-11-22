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
DROP PROCEDURE IF EXISTS `" . Tables::GET_BEST_VIEW_BY_DATE_RANGE . "`;

CREATE PROCEDURE `" . Tables::GET_BEST_VIEW_BY_DATE_RANGE . "`(
    IN `fromDate` DATETIME,
    IN `toDate` DATETIME,
    IN `targetType` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
    IN `limitResults` INT
)
BEGIN
    IF `targetType` IS NULL THEN
        SELECT
            `target_type`,
            `target_id`,
            SUM(`view`) AS `total_views`
        FROM `" . Tables::TRACKS . "`
        WHERE `created_at` BETWEEN `fromDate` AND `toDate`
        GROUP BY `target_type`, `target_id`
        ORDER BY `total_views` DESC
        LIMIT `limitResults`;
    ELSE
        SELECT
            `target_type`,
            `target_id`,
            SUM(`view`) AS `total_views`
        FROM `" . Tables::TRACKS . "`
        WHERE `created_at` BETWEEN `fromDate` AND `toDate`
        AND `target_type` = `targetType`
        GROUP BY `target_type`, `target_id`
        ORDER BY `total_views` DESC
        LIMIT `limitResults`;
    END IF;
END;";
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
DROP PROCEDURE IF EXISTS `" . Tables::GET_BEST_VIEW_BY_DATE_RANGE . "`;
";

        $connection->unprepared($sql);
    }
};

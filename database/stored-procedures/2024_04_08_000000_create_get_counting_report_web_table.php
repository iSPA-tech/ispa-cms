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
DROP PROCEDURE IF EXISTS `" . Tables::GET_TOTAL_REPORT_WEB . "`;

CREATE PROCEDURE `" . Tables::GET_TOTAL_REPORT_WEB . "`(IN input_date DATETIME)
BEGIN
    DECLARE total_views INT;
    DECLARE total_visitor INT;

    SELECT SUM(`views`) INTO total_views FROM `" . Tables::TRACKING_DAILY . "`;
    SELECT SUM(`visitors`) INTO total_visitor FROM `" . Tables::TRACKING_DAILY . "`;

    SELECT total_views, total_visitor;
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
DROP PROCEDURE IF EXISTS `" . Tables::GET_TOTAL_REPORT_WEB . "`;
";

        $connection->unprepared($sql);
    }
};

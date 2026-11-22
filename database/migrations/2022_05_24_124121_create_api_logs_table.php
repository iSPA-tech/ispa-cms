<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Models\User;
use App\Constants\Tables as AppTables;
use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create(Tables::API_LOGS, static function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique()->nullable();
            $table->char('type', 20)->nullable()->comment('incoming, outgoing');
            $table->char('method', 10)->nullable();
            $table->ipAddress()->nullable();
            $table->string('user_agent', 2000)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('domain', 500)->nullable();
            $table->longText('data_request')->nullable();
            $table->longText('data_response')->nullable();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('time_start')->nullable();
            $table->integer('duration')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $driver = \Illuminate\Support\Facades\DB::getDriverName();

            if ($driver === 'mysql') {
                $table->fullText(['request_id', 'domain'], sprintf(AppTables::FULLTEXT_SEARCH, Tables::API_LOGS));
            } elseif ($driver === 'pgsql') {
                $table->fullText(['domain'], sprintf(AppTables::FULLTEXT_SEARCH, Tables::API_LOGS));
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(Tables::API_LOGS);
    }
};

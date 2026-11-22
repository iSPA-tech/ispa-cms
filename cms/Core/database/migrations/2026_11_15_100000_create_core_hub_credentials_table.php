<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store the platform hub site credentials (encrypted with APP_KEY) and the hashed install setup code.
     *
     * A dedicated table instead of the `settings` store: private settings rows are copied decrypted into the Redis
     * runtime map by `cms:setting-initialize` and are listed and editable through the generic admin settings screen.
     */
    public function up(): void
    {
        if (Schema::hasTable('core_hub_credentials')) {
            return;
        }

        Schema::create('core_hub_credentials', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 32)->unique()->comment('site | setup_code');
            $table->text('value')->nullable()->comment('site: Crypt payload; setup_code: sha256 of the code');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Drop the table again.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_hub_credentials');
    }
};

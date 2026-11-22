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
     * One-tap hub connect attempts (plan 2026-11-22-hub-one-tap-connect): only the sha256 of the state and the
     * encrypted PKCE verifier are kept, bound to the admin who started the attempt; single use, 10 minutes.
     */
    public function up(): void
    {
        if (Schema::hasTable('core_hub_connect_attempts')) {
            return;
        }

        Schema::create('core_hub_connect_attempts', static function (Blueprint $table): void {
            $table->id();
            $table->char('state_hash', 64)->unique()->comment('sha256 of the state sent through the browser');
            $table->text('verifier')->comment('Crypt payload of the PKCE code_verifier; never leaves the server');
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Drop the table again.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_hub_connect_attempts');
    }
};

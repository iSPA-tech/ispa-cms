<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;

use App\Models\User;
use Cms\Core\Models\Role;
use App\Constants\UserType;
use App\Enums\UserStatusEnum;
use Illuminate\Support\Str;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UsersTableSeeder extends Seeder
{
    /**
     * Create the first webmaster from the MASTER_* environment values and link it to the Webmaster role.
     *
     * Nothing has a default: without MASTER_EMAIL the seeder does nothing, and without MASTER_PASSWORD it only links
     * an account that already exists. `cms:install` passes all four values for a self-installed site, so a fresh
     * install never gets an account with a known password.
     *
     * @return void
     */
    public function run(): void
    {
        $email = trim((string) env('MASTER_EMAIL'));
        if ($email === '') {
            Log::info('UsersTableSeeder: MASTER_EMAIL is not set, no webmaster account seeded.');

            return;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user) {
            Log::info("User exists, skipping create: {$email}");
        } else {
            $password = (string) env('MASTER_PASSWORD');
            if ($password === '') {
                Log::warning("UsersTableSeeder: MASTER_PASSWORD is not set, account not created: {$email}");

                return;
            }

            $username = trim((string) env('MASTER_USERNAME')) ?: Str::before($email, '@');
            $user = User::query()->create([
                'uuid' => Str::uuid(),
                'username' => $username,
                'name' => trim((string) env('MASTER_NAME')) ?: $username,
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'remember_token' => null,
                'type' => UserType::WEBMASTER,
                'level' => 0,
                'status' => UserStatusEnum::USER_ACTIVE->value,
            ]);
        }

        $role = Role::query()->where('name', 'Webmaster')->first();

        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
            Log::info("Role already assigned, skipping pivot insert: {$email} -> Webmaster");
        }
    }
}

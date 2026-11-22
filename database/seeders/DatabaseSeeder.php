<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Only seeders of shipped modules (edition open or premium) are named here, each guarded so a deployment without
     * the module skips it. Seeders of client modules and client projects run from
     * `Database\Seeders\Internal\ClientSeeder`, which only the private repo carries.
     *
     * @return void
     */
    public function run(): void
    {
        // Achievements is an optional module (not in every install).
        if (class_exists(\Cms\Achievements\Database\Seeders\AchievementCatalogSeeder::class)) {
            $this->call(\Cms\Achievements\Database\Seeders\AchievementCatalogSeeder::class);
        }
        // Extended contact-group settings (org email, feedback phone, per-source notify emails).
        // Definition-only + idempotent; global (every deployment gets the fields, empty by default).
        $this->call(\Cms\Core\Database\Seeders\ContactSettingsExtensionSeeder::class);
        // Mobile app registry rows + permissions (idempotent; ships only where MobileApp is deployed).
        if (class_exists(\Cms\MobileApp\Database\Seeders\MobileAppDatabaseSeeder::class)) {
            $this->call(\Cms\MobileApp\Database\Seeders\MobileAppDatabaseSeeder::class);
        }
        // AI runtime registry rows. Module ownership: Core seeds only provider env
        // models + its own news-compliance tasks; every other module seeds its own
        // task keys via its module seeder, guarded so deployments without the
        // module skip it. All idempotent and never overwrite admin edits.
        $this->call(\Cms\Core\Database\Seeders\AiRuntimeSettingSeeder::class);
        foreach ([
            \Cms\AutoDiscover\Database\Seeders\AutoDiscoverAiRuntimeTaskSeeder::class,
            \Cms\Education\Database\Seeders\EducationAiRuntimeTaskSeeder::class,
            \Cms\Content\Database\Seeders\ContentAiRuntimeTaskSeeder::class,
        ] as $aiRuntimeModuleSeeder) {
            if (class_exists($aiRuntimeModuleSeeder)) {
                $this->call($aiRuntimeModuleSeeder);
            }
        }

        // Client modules and client projects (private repo only).
        if (class_exists(\Database\Seeders\Internal\ClientSeeder::class)) {
            $this->call(\Database\Seeders\Internal\ClientSeeder::class);
        }
    }
}

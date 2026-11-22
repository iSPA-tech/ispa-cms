<?php

declare(strict_types=1);

namespace Cms\Skeleton\Database\Seeders;

use Illuminate\Database\Seeder;

/** Seeds all the data the Skeleton module needs in a fresh database. */
final class SkeletonDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SkeletonPermissionSeeder::class,
            SkeletonTranslateTableSeeder::class,
            // @mode full
            SkeletonDemoSeeder::class,
            // @endmode
        ]);
    }
}

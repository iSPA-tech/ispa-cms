<?php

declare(strict_types=1);

namespace Cms\Skeleton\Database\Seeders;

use Cms\Skeleton\Models\SkeletonThing;
use Illuminate\Database\Seeder;

/** Sample rows for a demo or local site; running it twice adds no duplicates. */
final class SkeletonDemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (require __DIR__ . '/data/things.php' as $row) {
            SkeletonThing::query()->firstOrCreate(['name' => $row['name']], $row);
        }
    }
}

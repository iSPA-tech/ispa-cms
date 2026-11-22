<?php

namespace Cms\Widget\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Chunk 2 Task 14 adds the first real seeder call: the frozen permission
 * inventory. Later tasks append their own seeders here as they are added.
 */
class WidgetDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            WidgetPermissionTableSeeder::class,
        ]);
    }
}

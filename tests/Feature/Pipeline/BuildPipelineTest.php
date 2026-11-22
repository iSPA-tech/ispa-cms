<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;

class BuildPipelineTest extends TestCase
{
    public function test_pipeline_can_migrate_and_seed_sqlite_database(): void
    {
        $databasePath = database_path('database.sqlite');
        $original = [
            'database.default' => Config::get('database.default'),
            'database.connections.sqlite.database' => Config::get('database.connections.sqlite.database'),
            'cache.default' => Config::get('cache.default'),
            'session.driver' => Config::get('session.driver'),
            'queue.default' => Config::get('queue.default'),
        ];

        try {
            if (File::exists($databasePath)) {
                File::delete($databasePath);
            }

            File::put($databasePath, '');

            Config::set('database.default', 'sqlite');
            Config::set('database.connections.sqlite.database', $databasePath);
            Config::set('cache.default', 'array');
            Config::set('session.driver', 'array');
            Config::set('queue.default', 'sync');

            DB::purge('sqlite');
            DB::reconnect('sqlite');

            $migrateExit = Artisan::call('migrate', [
                '--force' => true,
                '--no-interaction' => true,
            ]);
            $migrateOutput = Artisan::output();

            $this->assertSame(0, $migrateExit, $migrateOutput);

            $seedExit = Artisan::call('db:seed', [
                '--force' => true,
                '--no-interaction' => true,
            ]);
            $seedOutput = Artisan::output();

            $this->assertSame(0, $seedExit, $seedOutput);
        } finally {
            foreach ($original as $key => $value) {
                Config::set($key, $value);
            }

            DB::purge('sqlite');

            if (File::exists($databasePath)) {
                File::delete($databasePath);
            }
        }
    }
}

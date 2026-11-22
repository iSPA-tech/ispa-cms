<?php

declare(strict_types=1);

use Cms\Core\Services\Backup\Dump\MysqlDumper;
use Illuminate\Support\Facades\DB;

uses(Cms\Core\Tests\TestCase::class);

it('dumps a scratch MySQL database end to end', function (): void {
    $base = (array) config('database.connections.mysql');
    $existing = (string) (getenv('BACKUP_IT_MYSQL_DATABASE') ?: '');
    $name = $existing !== '' ? $existing : 'ispa_backup_it_' . bin2hex(random_bytes(4));
    $dir = sys_get_temp_dir() . '/mysqldumper-it-' . bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);

    // phpunit.xml points the default connection at sqlite :memory:, so never trust a ':memory:' database name.
    $adminConfig = array_merge($base, ['driver' => 'mysql', 'database' => $existing !== '' ? $existing : null]);
    config(['database.connections.bk_it_admin' => $adminConfig]);
    $admin = DB::connection('bk_it_admin');
    if ($existing === '') {
        $admin->statement("CREATE DATABASE `{$name}`");
    }
    $prefix = 'bkit_' . bin2hex(random_bytes(4));
    $t1 = $existing !== '' ? "{$prefix}_t1" : 't1';
    $t2 = $existing !== '' ? "{$prefix}_t2" : 't2';

    try {
        config([
            'core.backup.staging_path' => $dir . '/staging',
            'database.connections.bk_it' => array_merge($adminConfig, ['database' => $name]),
        ]);
        $pdo = DB::connection('bk_it');
        $pdo->statement("CREATE TABLE `{$t1}` (id INT PRIMARY KEY, v VARCHAR(20))");
        $pdo->statement("CREATE TABLE `{$t2}` (id INT PRIMARY KEY)");
        $pdo->statement("INSERT INTO `{$t1}` VALUES (1, 'x')");

        $result = (new MysqlDumper())->dump('bk_it', $dir . '/it.sql.gz');
        $sql = (string) gzdecode((string) file_get_contents($dir . '/it.sql.gz'));

        expect($result->tablesDumped)->toBe($result->tablesExpected)->and($result->tablesDumped)->toBeGreaterThanOrEqual(2)
            ->and($sql)->toContain("CREATE TABLE `{$t1}`")->toContain("CREATE TABLE `{$t2}`");
    } finally {
        if ($existing !== '') {
            DB::connection('bk_it_admin')->statement("DROP TABLE IF EXISTS `{$name}`.`{$t1}`");
            DB::connection('bk_it_admin')->statement("DROP TABLE IF EXISTS `{$name}`.`{$t2}`");
        } else {
            $admin->statement("DROP DATABASE IF EXISTS `{$name}`");
        }
        DB::purge('bk_it');
        DB::purge('bk_it_admin');
        foreach (glob($dir . '/{staging/*,*}', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir . '/staging');
        @rmdir($dir);
    }
})->skip(fn () => getenv('BACKUP_IT_MYSQL') !== '1', 'Set BACKUP_IT_MYSQL=1 with a local MySQL to run');

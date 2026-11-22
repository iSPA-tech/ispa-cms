<?php

declare(strict_types=1);

uses(\Tests\TestCase::class);

use App\Support\Modules\ArtisanSubprocess;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * ArtisanSubprocess: clean environment, PHP CLI binary only, argv array,
 * output capped at 2 KB. No real process runs (Process::fake).
 */
test('environment unsets every inherited variable except PATH, HOME, LANG, LC_ALL and TZ', function (): void {
    $map = (new ArtisanSubprocess())->environment([
        'PATH' => '/usr/bin',
        'HOME' => '/home/web',
        'LANG' => 'C.UTF-8',
        'LC_ALL' => 'C.UTF-8',
        'TZ' => 'Asia/Ho_Chi_Minh',
        'APP_ENV' => 'production',
        'DB_PASSWORD' => 'from-supervisor',
        'HTTP_HOST' => 'example.test',
        0 => 'numeric key',
    ]);

    expect($map)->toBe([
        'APP_ENV' => false,
        'DB_PASSWORD' => false,
        'HTTP_HOST' => false,
    ]);
});

test('inherited environment covers getenv, $_ENV and $_SERVER keys', function (): void {
    $_ENV['P3T17_ONLY_ENV'] = 'x';
    $_SERVER['P3T17_ONLY_SERVER'] = 'y';

    try {
        $subprocess = new ArtisanSubprocess();
        $map = $subprocess->environment($subprocess->inheritedEnvironment());

        expect($map)->toHaveKey('P3T17_ONLY_ENV', false)
            ->and($map)->toHaveKey('P3T17_ONLY_SERVER', false)
            ->and($map)->not->toHaveKey('PATH');
    } finally {
        unset($_ENV['P3T17_ONLY_ENV'], $_SERVER['P3T17_ONLY_SERVER']);
    }
});

test('an fpm binary is refused', function (string $binary): void {
    config(['modules.php_cli' => $binary]);

    (new ArtisanSubprocess())->phpBinary();
})->with(['/usr/sbin/php-fpm8.3', '/usr/local/sbin/PHP-FPM'])->throws(RuntimeException::class, 'fpm');

test('the configured CLI binary is used', function (): void {
    config(['modules.php_cli' => '/usr/bin/php8.3']);

    expect((new ArtisanSubprocess())->phpBinary())->toBe('/usr/bin/php8.3');
});

test('run passes an argv array from base_path with the clean environment and truncates output to 2 KB', function (): void {
    config(['modules.php_cli' => '/usr/bin/php8.3']);
    Process::fake(['*' => Process::result(str_repeat('a', 5000), str_repeat('e', 3000), 1)]);

    $result = (new ArtisanSubprocess())->run(['route:cache'], 30);

    expect(strlen($result->output()))->toBe(2048)
        ->and(strlen($result->errorOutput()))->toBe(2048)
        ->and($result->failed())->toBeTrue()
        ->and($result->exitCode())->toBe(1);

    Process::assertRan(function (PendingProcess $process): bool {
        return $process->command === ['/usr/bin/php8.3', base_path('artisan'), 'route:cache']
            && $process->path === base_path()
            && $process->timeout === 30
            && ! array_key_exists('PATH', $process->environment)
            && collect($process->environment)->every(fn ($value) => $value === false);
    });
});

test('truncate keeps short output and cuts long output', function (): void {
    expect(ArtisanSubprocess::truncate('short'))->toBe('short')
        ->and(ArtisanSubprocess::truncate(str_repeat('x', 2049)))->toBe(str_repeat('x', 2048));
});

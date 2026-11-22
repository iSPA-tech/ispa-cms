<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Services\SystemInfoService;

test('system info service formats bytes consistently', function () {
    $service = new SystemInfoService();
    $method = new \ReflectionMethod($service, 'formatBytes');
    $method->setAccessible(true);

    expect($method->invoke($service, 0))->toBe('0 B');
    expect($method->invoke($service, -5))->toBe('0 B');
    expect($method->invoke($service, 1024))->toBe('1.00 KB');
    expect($method->invoke($service, 1536, 1))->toBe('1.5 KB');
});

test('system info service caches expensive server sections', function () {
    $service = new SystemInfoService();
    $method = new \ReflectionMethod($service, 'rememberSection');
    $method->setAccessible(true);

    $section = 'unit_test_' . uniqid();
    cache()->forget('core.system_info.' . $section);

    $calls = 0;
    $resolver = static function () use (&$calls): array {
        $calls++;

        return ['calls' => $calls];
    };

    $first = $method->invoke($service, $section, 60, $resolver);
    $second = $method->invoke($service, $section, 60, $resolver);

    cache()->forget('core.system_info.' . $section);

    expect($first)->toBe(['calls' => 1])
        ->and($second)->toBe(['calls' => 1])
        ->and($calls)->toBe(1);
});

test('system info service uses bounded directory size checks', function () {
    $service = new SystemInfoService();
    $method = new \ReflectionMethod($service, 'getDirectorySize');
    $method->setAccessible(true);

    $directory = sys_get_temp_dir() . '/system-info-' . uniqid();
    mkdir($directory);
    file_put_contents($directory . '/sample.txt', str_repeat('a', 2048));

    try {
        $size = $method->invoke($service, $directory);
    } finally {
        @unlink($directory . '/sample.txt');
        @rmdir($directory);
    }

    expect($size)->toBeString()
        ->and($size)->not->toBe('N/A')
        ->and($size)->toContain('KB');
});

test('system info service disables composer package mutations', function () {
    $service = new SystemInfoService();

    $result = $service->updateComposerPackage('vendor/package; rm -rf /', true);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('disabled');
});

test('system info service does not use shell exec for composer checks', function () {
    $reflection = new \ReflectionClass(SystemInfoService::class);
    $source = file_get_contents((string) $reflection->getFileName());

    expect($source)->not->toContain('shell_exec');
});

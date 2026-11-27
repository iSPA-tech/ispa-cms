<?php

use App\Console\Commands\GenerateSettingCommand;

uses(\Tests\TestCase::class);

test('shared smtp settings always synchronize to environment', function (): void {
    expect(method_exists(GenerateSettingCommand::class, 'shouldSynchronizeToEnvironment'))->toBeTrue();

    if (!method_exists(GenerateSettingCommand::class, 'shouldSynchronizeToEnvironment')) {
        return;
    }

    $method = new ReflectionMethod(GenerateSettingCommand::class, 'shouldSynchronizeToEnvironment');
    $command = app(GenerateSettingCommand::class);

    foreach ([
        'email.MAIL_MAILER',
        'email.MAIL_HOST',
        'email.MAIL_PORT',
        'email.MAIL_ENCRYPTION',
        'email.MAIL_USERNAME',
        'email.MAIL_PASSWORD',
        'email.MAIL_FROM_ADDRESS',
        'email.MAIL_FROM_NAME',
        'email.MAIL_REPLY',
    ] as $settingKey) {
        expect($method->invoke($command, $settingKey, []))->toBeTrue();
    }

    expect($method->invoke($command, 'advanced.CACHE_ENABLE', []))->toBeFalse();
    expect($method->invoke($command, 'advanced.CACHE_ENABLE', ['isEnv' => true]))->toBeTrue();
});

/*
 * 2026-11-27: the command ended with `config:clear` + `cache:clear`. On a Redis database shared by several sites
 * (server .215) `cache:clear` empties every site's cache, and every admin settings save runs this command. The refresh
 * must touch only what the settings feed and leave other cache entries alone.
 */
test('the refresh after generating settings never flushes the application cache', function (): void {
    \Illuminate\Support\Facades\Cache::put('generate-setting-test:sentinel', 'kept', 600);
    \Illuminate\Support\Facades\Artisan::spy();

    $method = new ReflectionMethod(GenerateSettingCommand::class, 'refreshSettingCaches');
    $method->invoke(app(GenerateSettingCommand::class));

    expect(\Illuminate\Support\Facades\Cache::get('generate-setting-test:sentinel'))->toBe('kept');
    \Illuminate\Support\Facades\Artisan::shouldNotHaveReceived('call', ['cache:clear']);
    \Illuminate\Support\Facades\Artisan::shouldNotHaveReceived('call', ['config:clear']);
    // The test app has no cached configuration, so it stays uncached.
    \Illuminate\Support\Facades\Artisan::shouldNotHaveReceived('call', ['config:cache']);
});

test('the command source no longer clears the whole cache or the config cache', function (): void {
    $source = file_get_contents((new ReflectionClass(GenerateSettingCommand::class))->getFileName());

    expect($source)->not->toContain("'cache:clear'")
        ->and($source)->not->toContain("'config:clear'");
});

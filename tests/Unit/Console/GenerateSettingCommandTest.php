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

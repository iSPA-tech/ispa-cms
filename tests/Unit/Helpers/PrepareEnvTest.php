<?php

use Dotenv\Dotenv;

uses(\Tests\TestCase::class);

test('prepare env replaces quoted mail values and preserves special characters', function (): void {
    $env = "MAIL_FROM_NAME='Old Sender'\nMAIL_PASSWORD=old-password\n";

    $env = prepare_env($env, 'MAIL_FROM_NAME', (object) ['value' => 'Example Org']);
    $env = prepare_env($env, 'MAIL_PASSWORD', (object) ['value' => 'mail pass#$"\\value']);

    $parsed = Dotenv::parse($env);

    expect($parsed['MAIL_FROM_NAME'])->toBe('Example Org')
        ->and($parsed['MAIL_PASSWORD'])->toBe('mail pass#$"\\value');
});

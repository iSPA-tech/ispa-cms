<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

/*
 * 2026-11-28: put_config() logged the whole settings array at DEBUG level, so every settings save wrote mail provider
 * keys and ad/HTML snippets into storage/logs. The log line may name the file, the groups and a key count, never a value.
 */
it('logs the config path, groups and key count but never a setting value', function (): void {
    $logged = [];
    $capture = function (...$args) use (&$logged): void {
        $logged[] = $args;
    };
    $spy = Log::spy();
    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
        $spy->shouldReceive($level)->andReturnUsing($capture);
    }

    $secrets = ['hunter2-mail-password', 'aws-secret-key-0042', 'ad-snippet-777'];
    $dir = storage_path('framework/testing/put-config-log');
    File::ensureDirectoryExists($dir);

    try {
        put_config([
            'email' => ['MAIL_PASSWORD' => $secrets[0], 'AWS_SECRET_ACCESS_KEY' => $secrets[1]],
            'ads' => ['head' => '<script>' . $secrets[2] . '</script>'],
            'general' => ['name' => 'Example site'],
        ], '../storage/framework/testing/put-config-log/probe');

        // The file itself still carries the full payload: only the log was changed.
        $written = file_get_contents($dir . '/probe.php');
        foreach ($secrets as $secret) {
            expect($written)->toContain($secret);
        }
    } finally {
        File::deleteDirectory($dir);
    }

    expect($logged)->not->toBeEmpty();

    $serialized = json_encode($logged);
    foreach ($secrets as $secret) {
        expect($serialized)->not->toContain($secret);
    }
    expect($serialized)->not->toContain('Example site');

    $writing = collect($logged)->first(fn (array $call): bool => str_contains((string) ($call[0] ?? ''), 'Writing config file'));
    expect($writing)->not->toBeNull()
        ->and($writing[0])->toContain('put-config-log/probe.php')
        ->and($writing[1])->toBe(['groups' => ['email', 'ads', 'general'], 'keys' => 4]);
});

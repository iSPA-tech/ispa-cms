<?php

use Cms\Core\Agents\Runtime\ToolExecutionTimeout;

test('execute runs callable when no timeout set', function () {
    $result = ToolExecutionTimeout::execute(fn () => 'ok', null);

    expect($result)->toBe('ok');
});

test('execute runs callable when timeout is zero', function () {
    $result = ToolExecutionTimeout::execute(fn () => 'ok', 0);

    expect($result)->toBe('ok');
});

test('execute runs callable within timeout', function () {
    $result = ToolExecutionTimeout::execute(fn () => 42, 30);

    expect($result)->toBe(42);
});

test('execute restores previous time limit after execution', function () {
    // Capture the current max_execution_time before the test
    $originalLimit = (int) ini_get('max_execution_time');

    // Run with a specific timeout — after completion the original limit should be restored
    $result = ToolExecutionTimeout::execute(fn () => 'done', 15);

    $restoredLimit = (int) ini_get('max_execution_time');

    expect($result)->toBe('done')
        ->and($restoredLimit)->toBe($originalLimit);
});

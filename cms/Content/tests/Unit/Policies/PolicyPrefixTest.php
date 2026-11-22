<?php

uses(\Tests\TestCase::class);

it('content policies expose expected prefixes', function (string $policyClass, string $expected) {
    $policy = new $policyClass();
    $method = new ReflectionMethod($policyClass, 'prefix');
    $method->setAccessible(true);

    expect($method->invoke($policy))->toBe($expected);
})->with([
    [\Cms\Content\Policies\PostPolicy::class, 'post'],
]);

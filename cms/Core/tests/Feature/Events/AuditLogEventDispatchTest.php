<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Http\Request;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Interfaces\AuditLogInterface;

test('dispatch triggers listener and persists', function () {
    $request = Request::create('http://localhost/any', 'POST', server: [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_USER_AGENT' => 'PHPUnit',
    ]);
    $this->app->instance('request', $request);

    $mock = $this->createMock(AuditLogInterface::class);
    $mock->expects($this->once())
        ->method('create')
        ->with($this->arrayHasKey('action'));
    $this->app->instance(AuditLogInterface::class, $mock);

    event(new AuditLogEvent('created', ['a' => 1], 'App\\Models\\X', 1));
});

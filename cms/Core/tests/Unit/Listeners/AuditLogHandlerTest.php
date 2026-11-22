<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Http\Request;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Interfaces\AuditLogInterface;
use Cms\Core\Listeners\AuditLogHandler;

test('AuditLogHandler persists expected payload', function () {
    $request = Request::create(
        uri: 'http://localhost/admin/things/42',
        method: 'PUT',
        server: [
            'REMOTE_ADDR' => '10.0.0.12',
            'HTTP_USER_AGENT' => 'PHPUnit/UA',
            'HTTP_REFERER' => 'https://example.com/ref',
        ]
    );
    $this->app->instance('request', $request);

    $expected = [
        'action' => 'updated',
        'changed' => ['name' => ['old' => 'A', 'new' => 'B']],
        'type' => 'App\\Models\\Thing',
        'id' => 42,
        'icon' => 'edit',
        'color' => 'blue',
        'user_id' => 123,
    ];

    $repo = $this->createMock(AuditLogInterface::class);
    $repo->expects($this->once())
        ->method('create')
        ->with($this->callback(function (array $payload) use ($expected) {
            return $payload['action'] === 'action.' . $expected['action']
                && $payload['changed'] === $expected['changed']
                && $payload['target_type'] === $expected['type']
                && $payload['target_id'] === $expected['id']
                && $payload['ip_address'] === '10.0.0.12'
                && $payload['agent'] === 'PHPUnit/UA'
                && $payload['url'] === 'https://example.com/ref'
                && $payload['options']['icon'] === $expected['icon']
                && $payload['options']['color'] === $expected['color']
                && $payload['options']['method'] === 'PUT'
                && $payload['user_id'] === $expected['user_id'];
        }));

    $listener = new AuditLogHandler($repo);

    $event = new AuditLogEvent(
        action: $expected['action'],
        changed: $expected['changed'],
        type: $expected['type'],
        id: $expected['id'],
        icon: $expected['icon'],
        color: $expected['color'],
        user_id: $expected['user_id']
    );

    $listener->handle($event);
});

test('AuditLogHandler tolerates missing request metadata', function () {
    $request = Request::create(
        uri: 'http://localhost/admin/things',
        method: 'POST',
        server: [
            'REMOTE_ADDR' => '127.0.0.1',
        ]
    );
    $this->app->instance('request', $request);

    $repo = $this->createMock(AuditLogInterface::class);
    $repo->expects($this->once())
        ->method('create')
        ->with($this->callback(function (array $payload) {
            return $payload['ip_address'] === '127.0.0.1'
                && is_string($payload['agent'])
                && $payload['agent'] !== ''
                && $payload['url'] === null
                && $payload['options']['method'] === 'POST';
        }));

    $listener = new AuditLogHandler($repo);

    $event = new AuditLogEvent(
        action: 'created',
        changed: [],
        type: 'App\\Models\\Thing',
        id: 7,
        icon: null,
        color: null,
        user_id: 12
    );

    $listener->handle($event);
});

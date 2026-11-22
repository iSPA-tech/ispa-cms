<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Events\SendCodeVerify;

test('audit log event keeps provided user id', function () {
    $event = new AuditLogEvent(
        action: 'updated',
        changed: ['name' => ['old' => 'A', 'new' => 'B']],
        type: 'App\\Models\\Thing',
        id: 10,
        icon: 'edit',
        color: 'blue',
        user_id: 99
    );

    expect($event->user_id)->toBe(99);
    expect($event->broadcastOn())->toBe([]);
});

test('send code verify event sets default type', function () {
    $user = (object)['id' => 1];
    $event = new SendCodeVerify($user, '654321');

    expect($event->user)->toBe($user);
    expect($event->code)->toBe('654321');
    expect($event->type)->toBe('reset-password');
    expect($event->broadcastOn())->toBe([]);
});

<?php

use Cms\Core\Notifications\AdminNotification;
use Cms\Core\Services\CMSSettingUnified;

uses(Cms\Core\Tests\TestCase::class);

function fakeNotificationMailSetting(bool $on): void
{
    app()->instance(CMSSettingUnified::class, new class($on) extends CMSSettingUnified {
        public function __construct(private bool $on)
        {
        }

        public function get(string $settingKey, mixed $default = null, array $options = []): mixed
        {
            return $settingKey === 'notifications.mail' ? $this->on : $default;
        }
    });
}

test('a flat small payload is valid and stored through the database channel only by default', function () {
    fakeNotificationMailSetting(false);
    $n = new AdminNotification('backup.failed', ['name' => 'x', 'count' => 3, 'ok' => false, 'none' => null, 'ratio' => 1.5]);

    expect($n->isValid())->toBeTrue()
        ->and($n->via(new stdClass()))->toBe(['database'])
        ->and($n->toArray(new stdClass()))->toBe(['type' => 'backup.failed', 'payload' => $n->payload]);
});

test('an oversized payload is rejected and dropped', function () {
    fakeNotificationMailSetting(false);
    $n = new AdminNotification('big', ['text' => str_repeat('a', 1100)]);

    expect($n->isValid())->toBeFalse()->and($n->via(new stdClass()))->toBe([]);
});

test('nested arrays and objects are rejected', function () {
    fakeNotificationMailSetting(false);

    expect((new AdminNotification('n', ['a' => ['b' => 1]]))->isValid())->toBeFalse()
        ->and((new AdminNotification('n', ['a' => new stdClass()]))->isValid())->toBeFalse()
        ->and((new AdminNotification('n', ['a' => ['b' => 1]]))->via(new stdClass()))->toBe([]);
});

test('the mail channel is added only when the site setting is on', function () {
    fakeNotificationMailSetting(true);
    $n = new AdminNotification('backup.failed', ['a' => 1]);

    expect($n->via(new stdClass()))->toBe(['database', 'mail'])
        ->and($n->toMail(new stdClass())->subject)->toBe(__('mgs.mail.admin-notice-subject'));
});

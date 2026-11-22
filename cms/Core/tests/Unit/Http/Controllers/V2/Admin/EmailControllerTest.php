<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Http\Controllers\V2\Admin\EmailController;
use Cms\Core\Interfaces\EmailInterface;
use Cms\Core\Models\Email;
use App\Services\SpamEmailBlocklistService;
use App\Repositories\Interfaces\ContactInterface;
use Cms\Core\Services\Security\PersonalDataIdentityService;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;

function spamEmailBlocks(): SpamEmailBlocklistService
{
    return new SpamEmailBlocklistService(new PersonalDataIdentityService());
}

test('email fill uses the authenticated admin user id', function () {
    $this->actingAs(new GenericUser(['id' => 123]));
    $this->app->instance('request', Request::create('/api/v2/admin/email', 'POST', [
        'subject' => 'Test',
        'name' => 'Dev',
        'email' => 'jane@example.test',
        'cc' => [],
        'bcc' => [],
        'content' => '<p>Body</p>',
        'reply_id' => 0,
    ]));

    $emails = $this->createMock(EmailInterface::class);
    $emails->method('model')->willReturn(new Email());

    $controller = new EmailController(
        $emails,
        $this->createMock(ContactInterface::class),
        spamEmailBlocks(),
    );

    $fill = new ReflectionMethod($controller, 'fill');
    $fill->setAccessible(true);
    $model = $fill->invoke($controller);

    expect($model->user_id)->toBe(123);
});

test('email destroy resolves the mail record by UUID', function () {
    $uuid = 'c70e9407-39b7-4e07-9ce1-6d71e8906a5d';
    $emails = $this->createMock(EmailInterface::class);
    $emails
        ->expects($this->once())
        ->method('findBy')
        ->with(['uuid' => $uuid])
        ->willReturn(null);

    $controller = new EmailController(
        $emails,
        $this->createMock(ContactInterface::class),
        spamEmailBlocks(),
    );

    $controller->destroy($uuid);
});

<?php

declare(strict_types=1);

use Cms\Core\Models\AuditLog;
use Cms\Core\Notifications\AdminNotification;
use Cms\Core\Services\Workflow\Guard\WorkflowAudit;
use Cms\Core\Services\Workflow\Guard\WorkflowChangeAlerter;
use Cms\Core\Services\Workflow\Guard\WorkflowChangeReviewers;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * "Người duyệt thay đổi quy trình": the account that set a site up (a Webmaster) need not approve
 * other people's workflow changes nor hear about them. Named reviewers replace "every publisher and Webmaster" for the
 * change alerts and the second-publisher rule; empty keeps the old rule.
 */

require_once __DIR__ . '/guard-helpers.php';

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();
    config(['services.telegram.token' => '', 'services.telegram.chat_id' => '']);
    $this->setup = wgUser('setup-webmaster'); // user id 1 is always a Webmaster: the account that set the site up
    $this->reviewer = wgUser('reviewer', ['workflow_project.publish', 'workflow_project.update', 'workflow_project.viewAny']);
    $this->url = 'https://localhost/api/v2/admin/utilities/workflow/change-reviewers';
});

function crIds(array $users): array
{
    return array_map(static fn ($user): int => (int) $user->getKey(), $users);
}

function crOtherPublisher(array $authors): bool
{
    $method = new ReflectionMethod(WorkflowPublishService::class, 'otherPublisherExists');

    return $method->invoke(app(WorkflowPublishService::class), wgProject(), $authors);
}

test('with nobody named every publisher and Webmaster is told and counts as second publisher', function (): void {
    expect(crIds(app(WorkflowChangeAlerter::class)->recipients()))->toEqualCanonicalizing([$this->setup->id, $this->reviewer->id])
        // A change by the reviewer waits for the Webmaster who set the site up.
        ->and(crOtherPublisher([$this->reviewer->id]))->toBeTrue();
});

test('named reviewers alone are told and asked: the setup Webmaster is left out', function (): void {
    app(WorkflowChangeReviewers::class)->set([$this->reviewer->uuid], $this->setup);

    expect(crIds(app(WorkflowChangeAlerter::class)->recipients()))->toBe([$this->reviewer->id])
        // The reviewer's own change has no other reviewer: it takes the one-publisher path (cooling-off).
        ->and(crOtherPublisher([$this->reviewer->id]))->toBeFalse()
        // The setup Webmaster's change still waits for the named reviewer.
        ->and(crOtherPublisher([$this->setup->id]))->toBeTrue();
});

test('the Webmaster names reviewers through the admin API, audited and told before and after', function (): void {
    $publisher = wgUser('publisher', ['workflow_project.publish']);
    expect($this->actingAs($publisher, 'sanctum')->putJson($this->url, ['users' => [$publisher->uuid]])->status())->toBeIn([403, 404]);

    $this->actingAs($this->setup, 'sanctum')->getJson($this->url)->assertOk()->assertJsonPath('data.default', true);

    $data = $this->actingAs($this->setup, 'sanctum')->putJson($this->url, ['users' => [$this->reviewer->uuid]])->assertOk()->json('data');
    expect($data['default'])->toBeFalse()
        ->and($data['named'])->toBe([['uuid' => $this->reviewer->uuid, 'name' => 'reviewer', 'detail' => $this->reviewer->username]])
        ->and(AuditLog::query()->where('action', 'action.' . WorkflowAudit::REVIEWERS_CHANGED)->count())->toBe(1);
    // The people told before (the setup Webmaster, the publisher) and after (the reviewer) hear about it once each.
    foreach ([$this->setup, $publisher, $this->reviewer] as $user) {
        Notification::assertSentToTimes($user, AdminNotification::class, 1);
    }

    $this->actingAs($this->setup, 'sanctum')->putJson($this->url, ['users' => [(string) Str::uuid()]])->assertStatus(422);

    // The generic settings API cannot rewrite it.
    $this->actingAs($this->setup, 'sanctum')->postJson('https://localhost/api/v2/admin/setting/save', ['workflow' => ['workflow.change_reviewers' => '[]']]);
    expect(app(WorkflowChangeReviewers::class)->uuids())->toBe([$this->reviewer->uuid]);

    // An empty list asks everyone who may publish again.
    $this->actingAs($this->setup, 'sanctum')->putJson($this->url, ['users' => []])->assertOk()->assertJsonPath('data.default', true);
});

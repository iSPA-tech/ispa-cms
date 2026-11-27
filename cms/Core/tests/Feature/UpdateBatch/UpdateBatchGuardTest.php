<?php

declare(strict_types=1);

use Cms\Core\Models\BackgroundTask;
use Cms\Core\Services\UpdateBatch\UpdateBatches;
use Cms\Core\Tests\Feature\UpdateBatch\UpdateBatchFixtures;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

// Upgrade-all spec 2026-11-26 decision 8; plan Task 7: while a run is open the server refuses every other start that
// could slip into the gap between two items.

beforeEach(function () {
    Queue::fake();
    URL::forceScheme('https');
    $this->tmp = UpdateBatchFixtures::site($this);
    config(['queue.default' => 'redis', 'queue.connections.redis.retry_after' => 3000]);
    Sanctum::actingAs(UpdateBatchFixtures::webmaster());
});

afterEach(function () {
    UpdateBatchFixtures::tearDown($this->tmp);
});

function ugtStarts(): array
{
    return [
        ['post', '/api/v2/admin/utilities/modules/install', ['name' => 'Account', 'version' => '2026.11.05']],
        ['post', '/api/v2/admin/utilities/modules/upload', []],
        ['delete', '/api/v2/admin/utilities/modules/runtime/Webmail', ['confirm_name' => 'Webmail']],
        ['post', '/api/v2/admin/core-update', ['version' => '2026.12.1', 'confirm_backup' => true]],
        ['post', '/api/v2/admin/core-update/restore', ['confirm' => true]],
        ['post', '/api/v2/admin/widget/hub/ispa.news/download', []],
        ['post', '/api/v2/admin/widget/1/hub-update', []],
        ['post', '/api/v2/admin/workflows/library/ispa.church-story/install', []],
    ];
}

it('refuses every other start while a run is open', function (): void {
    BackgroundTask::query()->create(['type' => UpdateBatches::TASK_TYPE, 'module' => 'Core', 'status' => BackgroundTask::STATUS_RUNNING, 'started_by' => 1, 'result' => ['items' => []]]);

    foreach (ugtStarts() as [$method, $url, $body]) {
        $this->json($method, $url, $body)
            ->assertStatus(409)
            ->assertJsonPath('error_code', UpdateBatches::ERROR_RUNNING);
    }
});

it('lets a module install through when no run is open', function (): void {
    $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Account', 'version' => '2026.11.05'])
        ->assertJsonMissing(['error_code' => UpdateBatches::ERROR_RUNNING]);
});

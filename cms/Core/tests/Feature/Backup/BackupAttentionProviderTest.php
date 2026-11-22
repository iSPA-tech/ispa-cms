<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Dashboard\AttentionRegistry;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\BackupAttentionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function attentionUser(bool $webmaster): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isWebmaster')->andReturn($webmaster);

    return $user;
}

function attentionDestination(string $name, array $attrs): BackupDestination
{
    $d = BackupDestination::query()->create(['name' => $name, 'driver' => 'local', 'config' => ['root' => '/tmp/x']] + array_intersect_key($attrs, ['schedule' => 1, 'enabled' => 1]));
    $d->forceFill(array_diff_key($attrs, ['schedule' => 1, 'enabled' => 1]))->save();

    return $d;
}

function attentionEntries(User $user): array
{
    $registry = new AttentionRegistry();
    BackupAttentionProvider::register($registry);

    return $registry->forUser($user);
}

it('shows a Webmaster one problem for a failing scheduled destination only', function (): void {
    attentionDestination('Failing', ['schedule' => 'daily@02:00', 'last_success_at' => now()->subHours(5), 'last_failure_at' => now()->subHour(), 'created_at' => now()->subDays(9)]);
    attentionDestination('Healthy', ['schedule' => 'daily@02:00', 'last_success_at' => now()->subHours(2), 'created_at' => now()->subDays(9)]);
    attentionDestination('Unscheduled failing', ['last_failure_at' => now()->subHour(), 'created_at' => now()->subDays(9)]);

    $entries = attentionEntries(attentionUser(true));

    expect($entries)->toHaveCount(1)->and($entries[0]['key'])->toBe('core_backup_problem')
        ->and($entries[0]['count'])->toBe(1)->and($entries[0]['route'])->toBe('setting-backups');
});

it('counts a scheduled destination with no success in 26 hours but not a brand-new one', function (): void {
    attentionDestination('Stale', ['schedule' => 'daily@02:00', 'last_success_at' => now()->subHours(30), 'created_at' => now()->subDays(9)]);
    attentionDestination('New', ['schedule' => 'daily@02:00', 'created_at' => now()->subMinutes(10)]);
    attentionDestination('Never ran', ['schedule' => 'daily@02:00', 'created_at' => now()->subDays(2)]);

    expect(attentionEntries(attentionUser(true))[0]['count'])->toBe(2);
});

it('shows nothing to a user who is not a Webmaster', function (): void {
    attentionDestination('Failing', ['schedule' => 'daily@02:00', 'last_failure_at' => now(), 'created_at' => now()->subDays(9)]);

    expect(attentionEntries(attentionUser(false)))->toBe([]);
});

it('lists the unhealthy destinations as items without credentials or error details', function (): void {
    attentionDestination('Failing', ['schedule' => 'daily@02:00', 'last_success_at' => now()->subHours(5), 'last_failure_at' => now()->subHour(), 'last_error_code' => 'secret-code', 'created_at' => now()->subDays(9)]);
    attentionDestination('Healthy', ['schedule' => 'daily@02:00', 'last_success_at' => now()->subHours(2), 'created_at' => now()->subDays(9)]);

    $registry = new AttentionRegistry();
    BackupAttentionProvider::register($registry);
    $entry = $registry->forUser(attentionUser(true), true)[0];

    expect($entry['tone'])->toBe('danger')->and($entry['items'])->toHaveCount(1);
    $item = $entry['items'][0];
    expect($item['title'])->toBe('Failing')->and($item['route'])->toBe('setting-backups')
        ->and($item['at_label'])->toBe('text.my_tasks.at.failed')->and($item['subtitle'])->toBe('local')->and($item['at'])->toBeString();
    expect(json_encode($entry))->not->toContain('secret-code')->not->toContain('credentials');
});

it('labels a stale destination by its last success and a never-run one by its creation', function (): void {
    attentionDestination('Stale', ['schedule' => 'daily@02:00', 'last_success_at' => now()->subHours(30), 'created_at' => now()->subDays(9)]);
    attentionDestination('Never ran', ['schedule' => 'daily@02:00', 'created_at' => now()->subDays(2)]);

    $registry = new AttentionRegistry();
    BackupAttentionProvider::register($registry);
    $labels = array_column($registry->forUser(attentionUser(true), true)[0]['items'], 'at_label', 'title');

    expect($labels)->toBe(['Stale' => 'text.my_tasks.at.last_success', 'Never ran' => 'text.my_tasks.at.created']);
});

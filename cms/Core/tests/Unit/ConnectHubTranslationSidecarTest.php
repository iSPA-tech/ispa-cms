<?php

use Cms\Core\Services\Platform\SiteConnectService;
use Cms\Core\Services\Platform\SiteStatusService;

uses(\Cms\Core\Tests\TestCase::class);

/**
 * Load the connect screen sidecar keyed by row name.
 *
 * @return \Illuminate\Support\Collection<string, array<string, mixed>>
 */
function connectHubSidecarRows(): \Illuminate\Support\Collection
{
    return collect(require base_path('cms/Core/database/seeders/exported/translates-admin-connect-hub.php'))->keyBy('name');
}

it('gives every connect screen row an English and a Vietnamese text for the admin pack', function (): void {
    $rows = connectHubSidecarRows();

    expect($rows)->toHaveCount(44);

    foreach ($rows as $name => $row) {
        expect($name)->toStartWith('text.')
            ->and($row['group_name'])->toBe('text')
            ->and($row['modules'])->toBe(['Core'])
            ->and($row['for_adm'])->toBe(1)
            ->and($row['for_app'])->toBe(0)
            ->and($row)->not->toHaveKey('id')
            ->and(trim((string) $row['text']['en']))->not->toBe('')
            ->and(trim((string) $row['text']['vi']))->not->toBe('');
    }
});

it('covers every message key the site connect and site status services can return', function (): void {
    $rows = connectHubSidecarRows();
    $keys = [
        SiteStatusService::MESSAGE_CONNECTED,
        SiteStatusService::MESSAGE_PENDING,
        SiteStatusService::MESSAGE_NOT_CONNECTED,
        SiteStatusService::MESSAGE_NOT_CONFIGURED,
        SiteStatusService::MESSAGE_HUB_UNREACHABLE,
        SiteStatusService::MESSAGE_HUB_UNREACHABLE_GRACE,
    ];
    foreach ([
        SiteConnectService::OUTCOME_CONNECTED,
        SiteConnectService::OUTCOME_PENDING,
        SiteConnectService::OUTCOME_INVALID_INPUT,
        SiteConnectService::OUTCOME_BAD_SETUP_CODE,
        SiteConnectService::OUTCOME_MANAGED_BY_ENV,
        SiteConnectService::OUTCOME_REFUSED,
        SiteConnectService::OUTCOME_HUB_UNREACHABLE,
    ] as $outcome) {
        $keys[] = 'text.site_connect.' . $outcome;
    }
    $reasons = (new ReflectionClass(SiteConnectService::class))->getReflectionConstant('PENDING_REASONS')->getValue();
    foreach ($reasons as $reason) {
        $keys[] = 'text.site_connect.reason.' . $reason;
    }

    foreach ($keys as $key) {
        expect($rows->has($key))->toBeTrue("missing row for {$key}");
    }
});

it('has a row for every text.site_connect key the connect screen component uses', function (): void {
    $component = base_path('../base-admin/src/pages/auth/connect-hub.vue');
    if (! is_file($component)) {
        $this->markTestSkipped('base-admin is not beside ispa-cms in this layout.');
    }

    preg_match_all("/\\bt\\('(text\\.site_(?:connect|status)\\.[a-z_.]+)'/", (string) file_get_contents($component), $matches);
    $rows = connectHubSidecarRows();

    expect($matches[1])->not->toBeEmpty();
    foreach (array_unique($matches[1]) as $key) {
        expect($rows->has($key))->toBeTrue("missing row for {$key}");
    }
});

it('never uses a key as both a label and a parent of other keys', function (): void {
    $names = connectHubSidecarRows()->keys();

    foreach ($names as $name) {
        expect($names->contains(fn (string $other): bool => str_starts_with($other, $name . '.')))->toBeFalse("{$name} is also a branch");
    }
});

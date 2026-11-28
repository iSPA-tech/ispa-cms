<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

// Workflow schedules run unless a site turns them off: the weekly report template (trigger_schedule) never ran on
// installs that had to opt in with WORKFLOW_SCHEDULER_ENABLED.

function workflowScheduleConfigWith(?string $value): array
{
    $before = getenv('WORKFLOW_SCHEDULER_ENABLED');
    $value === null ? putenv('WORKFLOW_SCHEDULER_ENABLED') : putenv('WORKFLOW_SCHEDULER_ENABLED='.$value);
    unset($_ENV['WORKFLOW_SCHEDULER_ENABLED'], $_SERVER['WORKFLOW_SCHEDULER_ENABLED']);
    try {
        return require base_path('cms/Core/config/workflow_schedule.php');
    } finally {
        $before === false ? putenv('WORKFLOW_SCHEDULER_ENABLED') : putenv('WORKFLOW_SCHEDULER_ENABLED='.$before);
    }
}

test('workflow schedules are on when the site sets nothing', function () {
    expect(workflowScheduleConfigWith(null)['enabled'])->toBeTrue();
});

test('a site turns every schedule off with WORKFLOW_SCHEDULER_ENABLED=false', function () {
    expect(workflowScheduleConfigWith('false')['enabled'])->toBeFalse()
        ->and(workflowScheduleConfigWith('true')['enabled'])->toBeTrue();
});

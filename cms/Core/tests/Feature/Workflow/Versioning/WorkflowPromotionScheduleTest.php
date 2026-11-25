<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

uses(\Cms\Core\Tests\TestCase::class);

it('schedules the workflow minute tick even when cron-scheduled projects are off, so cooling-off changes go live', function (): void {
    config()->set('core.workflow_schedule.enabled', false);

    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('cms:workflow-run-scheduled');
});
